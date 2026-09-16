<?php

/**
 * RecordLogoutEvent Listener Tests
 *
 * Covers:
 *  - LOGOUT sysevent is recorded when Auth::logout() fires (via any logout path)
 *  - `via` field reflects UI vs API context
 *  - Session duration is calculated when the current session matches the last LOGIN event
 *  - Duration falls back to 0 when session IDs differ
 *  - Null user is handled gracefully
 *  - Listener is registered in AppServiceProvider for the Logout event
 *  - No duplicate LOGOUT events from the Wave LoginController
 *  - The POST /logout route records a LOGOUT event
 */

use App\Listeners\RecordLogoutEvent;
use App\Models\Sysevent;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Create a user with a LOGIN sysevent carrying the given session ID. */
function userWithLoginEvent(string $sessionId = 'test-session-abc'): User
{
    $user = User::factory()->create();

    Sysevent::create([
        'owner' => $user->id,
        'group' => $user->id,
        'type' => 'LOGIN',
        'status' => 'SUCCESS',
        'class' => 'ACTIVITY',
        'vault_id' => 0,
        'dir_id' => 0,
        'case_id' => 0,
        'payload' => json_encode([
            'message' => 'login success',
            'email' => $user->email,
            'via' => 'UI',
            'session' => $sessionId,
        ]),
    ]);

    return $user;
}

/** Fire the Logout event synchronously with the given user. */
function fireLogoutEvent(User $user): void
{
    $listener = new RecordLogoutEvent;
    $listener->handle(new Logout('web', $user));
}

// ---------------------------------------------------------------------------
// Core behaviour
// ---------------------------------------------------------------------------

it('records a LOGOUT sysevent when the listener fires', function () {
    $user = userWithLoginEvent();

    fireLogoutEvent($user);

    assertDatabaseHas('sysevents', [
        'owner' => $user->id,
        'type' => 'LOGOUT',
        'status' => 'SUCCESS',
        'class' => 'ACTIVITY',
    ]);
});

it('stores the correct email in the LOGOUT payload', function () {
    $user = userWithLoginEvent();

    fireLogoutEvent($user);

    $event = Sysevent::where('owner', $user->id)->where('type', 'LOGOUT')->first();
    $payload = json_decode($event->payload, true);

    expect($payload['email'])->toBe($user->email);
});

it('stores "logout success" message in the LOGOUT payload', function () {
    $user = userWithLoginEvent();

    fireLogoutEvent($user);

    $event = Sysevent::where('owner', $user->id)->where('type', 'LOGOUT')->first();
    $payload = json_decode($event->payload, true);

    expect($payload['message'])->toBe('logout success');
});

// ---------------------------------------------------------------------------
// `via` field
// ---------------------------------------------------------------------------

it('sets via=UI for non-API requests', function () {
    $user = userWithLoginEvent();

    // Default test request is not an API route
    fireLogoutEvent($user);

    $event = Sysevent::where('owner', $user->id)->where('type', 'LOGOUT')->first();
    $payload = json_decode($event->payload, true);

    expect($payload['via'])->toBe('UI');
});

it('sets via=API when the request path starts with api/', function () {
    $user = userWithLoginEvent();

    // Simulate an API request path
    $this->call('POST', '/api/logout');

    app('request')->server->set('REQUEST_URI', '/api/logout');
    request()->server->set('REQUEST_URI', '/api/logout');

    // Build an API request and bind it so request()->is('api/*') returns true
    $apiRequest = Request::create('/api/logout', 'POST');
    app()->instance('request', $apiRequest);

    $listener = new RecordLogoutEvent;
    $listener->handle(new Logout('web', $user));

    $event = Sysevent::where('owner', $user->id)->where('type', 'LOGOUT')->first();
    $payload = json_decode($event->payload, true);

    expect($payload['via'])->toBe('API');
});

// ---------------------------------------------------------------------------
// Session duration calculation
// ---------------------------------------------------------------------------

it('calculates duration when the session ID matches the last LOGIN event', function () {
    // Derive the test session ID so we can match it inside the listener.
    $sessionId = session()->getId();
    $user = userWithLoginEvent($sessionId); // LOGIN event carries the same session ID

    fireLogoutEvent($user);

    $event = Sysevent::where('owner', $user->id)->where('type', 'LOGOUT')->first();
    $payload = json_decode($event->payload, true);

    // Duration should be a HH:MM:SS string (even "00:00:00" is acceptable for fast tests).
    expect($payload['duration'])->toMatch('/^\d{2}:\d{2}:\d{2}$/');
});

it('falls back to duration=0 when the session ID does not match the last LOGIN event', function () {
    $user = userWithLoginEvent('login-session-aaa');

    // Test session has a different ID — no match
    session()->setId('different-session-bbb');

    fireLogoutEvent($user);

    $event = Sysevent::where('owner', $user->id)->where('type', 'LOGOUT')->first();
    $payload = json_decode($event->payload, true);

    expect($payload['duration'])->toBe(0);
});

it('falls back to duration=0 when no LOGIN event exists for the user', function () {
    $user = User::factory()->create(); // no LOGIN sysevent

    fireLogoutEvent($user);

    $event = Sysevent::where('owner', $user->id)->where('type', 'LOGOUT')->first();
    $payload = json_decode($event->payload, true);

    expect($payload['duration'])->toBe(0);
});

// ---------------------------------------------------------------------------
// Mil open-case pointer is cleared on logout (no cross-login case leak)
// ---------------------------------------------------------------------------

it('clears the Mil open-case session pointer on logout', function () {
    // Mimics a case opened in Browse SOS Report during the session.
    session(['mil_open_case' => ['did' => 7, 'cid' => 42]]);

    $user = userWithLoginEvent();
    fireLogoutEvent($user);

    // Without clearing, the next login on the same cookie would hydrate this and
    // answer /case questions with the previous session's data.
    expect(session()->has('mil_open_case'))->toBeFalse();
});

it('clears the Mil open-case pointer even when the event user is null', function () {
    session(['mil_open_case' => ['did' => 1, 'cid' => 2]]);

    $listener = new RecordLogoutEvent;
    $listener->handle(new Logout('web', null));

    expect(session()->has('mil_open_case'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Null / edge cases
// ---------------------------------------------------------------------------

it('does nothing when the event carries a null user', function () {
    $before = Sysevent::where('type', 'LOGOUT')->count();

    $event = new Logout('web', null);
    $listener = new RecordLogoutEvent;
    $listener->handle($event);

    assertDatabaseCount('sysevents', $before + Sysevent::where('type', 'LOGOUT')->count() - $before);
    expect(Sysevent::where('type', 'LOGOUT')->count())->toBe($before);
});

// ---------------------------------------------------------------------------
// Registration
// ---------------------------------------------------------------------------

it('is registered for the Logout event via AppServiceProvider', function () {
    $listeners = Event::getListeners(Logout::class);

    $found = collect($listeners)->contains(function ($listener) {
        if (is_array($listener)) {
            $class = is_object($listener[0]) ? get_class($listener[0]) : $listener[0];

            return str_contains($class, 'RecordLogoutEvent');
        }
        // Closure-wrapped listener — unwrap via reflection
        if ($listener instanceof Closure) {
            try {
                $rf = new ReflectionFunction($listener);
                $vars = $rf->getStaticVariables();

                return collect($vars)->contains(fn ($v) => is_string($v) && str_contains($v, 'RecordLogoutEvent'));
            } catch (Throwable) {
                return false;
            }
        }

        return is_string($listener) && str_contains($listener, 'RecordLogoutEvent');
    });

    expect($found)->toBeTrue('RecordLogoutEvent should be registered for Illuminate\Auth\Events\Logout');
});

// ---------------------------------------------------------------------------
// No duplicates — Wave LoginController no longer calls addEvent directly
// ---------------------------------------------------------------------------

it('produces exactly one LOGOUT event when Auth::logout() is called', function () {
    $sessionId = 'wave-logout-session';
    $user = userWithLoginEvent($sessionId);
    session()->setId($sessionId);

    Auth::setUser($user);
    Auth::logout(); // fires the Logout event → listener records it once

    expect(Sysevent::where('owner', $user->id)->where('type', 'LOGOUT')->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Integration — POST /logout records the event
// ---------------------------------------------------------------------------

it('records a LOGOUT event when POST /logout is called', function () {
    $user = userWithLoginEvent();
    $this->actingAs($user);

    $this->post('/logout');

    assertDatabaseHas('sysevents', [
        'owner' => $user->id,
        'type' => 'LOGOUT',
    ]);
});

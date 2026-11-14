<?php

use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

/*
 * The authenticated UI (dashboard + its /api/vaultState and /api/userInfo
 * polling) runs under the 'web' rate limiter. It was 120/min, which tripped
 * 429 during normal bursts (e.g. a Livewire refresh storm when an upload
 * completes). It is keyed per-user, so raising it is not a brute-force
 * concern — credential endpoints carry their own tight limits.
 */

it('caps the per-user web limiter at 300/min', function () {
    $user = User::factory()->create();

    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);

    $limit = call_user_func(RateLimiter::limiter('web'), $request);
    $limit = is_array($limit) ? $limit[0] : $limit;

    expect($limit->maxAttempts)->toBe(300);
    // Keyed by the user id, not the shared client IP.
    expect((string) $limit->key)->toBe((string) $user->id);
});

it('renders a branded 429 page', function () {
    $html = view('errors.429')->render();

    expect($html)
        ->toContain('429')
        ->toContain('Too Many Requests')
        ->toContain('sos-vault');
});

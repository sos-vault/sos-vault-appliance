<?php

/**
 * The Login listener must fail CLOSED when the kernel keyring has lost the
 * svault0 key: instead of letting VaultTools' constructor build an empty-key
 * Encrypter (raw 500 "Unsupported cipher or incorrect key length"), it logs the
 * user back out and throws a ValidationException so the login form redisplays a
 * friendly message.
 *
 * SvaultKeyStub shadows getSvaultKey() in the App\Listeners namespace; setting
 * $GLOBALS['__svault_stub_empty'] makes it return '' to simulate the empty keyring.
 */

use App\Listeners\closeVault;
use App\Listeners\initializeVault;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/SvaultKeyStub.php';

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

afterEach(function () {
    unset($GLOBALS['__svault_stub_empty']);
});

it('refuses login with a friendly message when the svault keyring is empty', function () {
    $GLOBALS['__svault_stub_empty'] = true;
    config(['app.vaultsDisabled' => 'FALSE']);

    $user = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $this->actingAs($user);
    expect(auth()->check())->toBeTrue();

    try {
        (new initializeVault)->handle(new Login('web', $user, false));
        $this->fail('Expected ValidationException was not thrown');
    } catch (ValidationException $e) {
        // Error is attached to the login form's email field and the partially
        // authenticated session was torn down.
        expect($e->errors())->toHaveKey('email')
            ->and(auth()->check())->toBeFalse();
    }
});

it('does not refuse login when the svault keyring is loaded', function () {
    $GLOBALS['__svault_stub_empty'] = false;
    config(['app.vaultsDisabled' => 'FALSE']);

    $user = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);

    // Exercise the guard in isolation (a full handle() with a live keyring would
    // proceed to real LUKS/OS operations, which unit tests must never do).
    $guard = new ReflectionMethod(initializeVault::class, 'assertVaultKeyringLoaded');
    $guard->setAccessible(true);

    $guard->invoke(null, $user); // must NOT throw
    expect(true)->toBeTrue();
});

it('closeVault does not throw during logout when the svault keyring is empty', function () {
    // Regression: initializeVault's own auth()->logout() (when it refuses a login)
    // fires the Logout event → closeVault. SessionGuard dispatches Logout while the
    // user is still set, so closeVault would build VaultTools → new Encrypter('', …)
    // → the same RuntimeException the refusal exists to prevent. Reproduce that
    // ordering by handling Logout with the user still authenticated.
    $GLOBALS['__svault_stub_empty'] = true;
    config(['app.vaultsDisabled' => 'FALSE']);

    $user = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $this->actingAs($user);

    (new closeVault)->handle(new Logout('web', $user)); // must NOT throw
    expect(true)->toBeTrue();
});

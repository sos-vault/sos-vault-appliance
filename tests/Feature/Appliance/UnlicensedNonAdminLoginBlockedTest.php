<?php

/**
 * BlockUnlicensedNonAdmin middleware — expiry recovery for non-admin users.
 *
 * When the appliance is unlicensed (no LocalLicense, or the only one has
 * expired), any authenticated non-admin user must be logged out on the next
 * request and redirected to /login with a flash error. Admin users are
 * unaffected — they need to be able to log in to renew the license.
 *
 * SaaS build is unaffected because applianceUnlicensed() returns false.
 */

use App\Http\Middleware\BlockUnlicensedNonAdmin;
use App\Models\LocalLicense;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

function makeFreshRequest(): Request
{
    $request = Request::create('/admin', 'GET');
    $request->setLaravelSession(app('session.store'));

    return $request;
}

it('logs out and redirects a non-admin on unlicensed appliance', function () {
    config(['product.type' => 'appliance']);

    $member = User::factory()->create();
    $member->syncRoles(['Team Member']);
    Auth::login($member);

    expect(Auth::check())->toBeTrue();

    $response = (new BlockUnlicensedNonAdmin)->handle(
        makeFreshRequest(),
        fn () => response('OK')
    );

    expect(Auth::check())->toBeFalse();
    expect($response->isRedirect(route('login')))->toBeTrue();
});

it('leaves an admin signed in on unlicensed appliance', function () {
    config(['product.type' => 'appliance']);

    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);
    Auth::login($admin);

    $response = (new BlockUnlicensedNonAdmin)->handle(
        makeFreshRequest(),
        fn () => response('OK')
    );

    expect(Auth::check())->toBeTrue();
    expect($response->getContent())->toBe('OK');
});

it('does not interfere when appliance has an ACTIVE license', function () {
    config(['product.type' => 'appliance']);

    LocalLicense::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 1,
        'machine_tokens' => ['sha256:test-host'],
        'seats' => 5,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'signed_license' => "-----BEGIN PGP SIGNED MESSAGE-----\n...stub...",
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
        'uploaded_by' => null,
    ]);

    $member = User::factory()->create();
    $member->syncRoles(['Team Member']);
    Auth::login($member);

    $response = (new BlockUnlicensedNonAdmin)->handle(
        makeFreshRequest(),
        fn () => response('OK')
    );

    expect(Auth::check())->toBeTrue();
    expect($response->getContent())->toBe('OK');
});

it('blocks the non-admin again the moment the license expires', function () {
    config(['product.type' => 'appliance']);

    $license = LocalLicense::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 1,
        'machine_tokens' => ['sha256:test-host'],
        'seats' => 5,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'signed_license' => "-----BEGIN PGP SIGNED MESSAGE-----\n...stub...",
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
        'uploaded_by' => null,
    ]);

    $member = User::factory()->create();
    $member->syncRoles(['Team Member']);
    Auth::login($member);

    // Expire it.
    $license->update(['expires_at' => now()->subDay()]);

    $response = (new BlockUnlicensedNonAdmin)->handle(
        makeFreshRequest(),
        fn () => response('OK')
    );

    expect(Auth::check())->toBeFalse();
    expect($response->isRedirect(route('login')))->toBeTrue();
});

it('does not interfere on SaaS even with no license rows', function () {
    config(['product.type' => 'saas']);

    $member = User::factory()->create();
    $member->syncRoles(['Team Member']);
    Auth::login($member);

    $response = (new BlockUnlicensedNonAdmin)->handle(
        makeFreshRequest(),
        fn () => response('OK')
    );

    expect(Auth::check())->toBeTrue();
    expect($response->getContent())->toBe('OK');
});

<?php

use App\Models\Sysevent;
use App\Models\User;
use App\Services\TwoFactorService;
use chillerlan\Authenticator\Authenticator;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Livewire\Volt\Volt;
use Wave\Setting;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->svc = app(TwoFactorService::class);
});

/** Produce the current valid TOTP code for a secret (acts as the phone app). */
function currentTotp(string $secret): string
{
    return (new Authenticator)->setSecret($secret)->code();
}

/** Re-enable mandatory admin 2FA (the suite default turns it off). */
function requireAdminTwoFactor(): void
{
    Setting::updateOrCreate(
        ['key' => 'auth.two_factor_required_for_admins'],
        ['display_name' => 'auth.two_factor_required_for_admins', 'value' => '1', 'type' => 'text', 'order' => 0]
    );
    Cache::forget('wave_settings');
}

// --- TOTP verification --------------------------------------------------------

it('verifies a current TOTP code and rejects a wrong one', function () {
    $secret = $this->svc->generateSecret();

    expect($this->svc->verifyCode($secret, currentTotp($secret)))->toBeTrue()
        ->and($this->svc->verifyCode($secret, '000000'))->toBeFalse()
        ->and($this->svc->verifyCode($secret, ''))->toBeFalse();
});

// --- Per-environment issuer ---------------------------------------------------

it('uses a distinct authenticator issuer per environment', function () {
    Config::set('product.type', 'saas');
    $this->app->detectEnvironment(fn () => 'local');
    expect($this->svc->issuer())->toBe('sos-vault-dev');

    $this->app->detectEnvironment(fn () => 'production');
    expect($this->svc->issuer())->toBe('sos-vault');

    $this->app->detectEnvironment(fn () => 'production');
    Config::set('product.type', 'appliance');
    expect($this->svc->issuer())->toBe('sos-vault-self-hosted');
})->after(fn () => $this->app->detectEnvironment(fn () => 'testing'));

it('embeds the issuer and account in the otpauth uri and renders a local QR', function () {
    Config::set('product.type', 'saas');
    $this->app->detectEnvironment(fn () => 'production');

    $secret = $this->svc->generateSecret();
    $uri = $this->svc->otpauthUri($secret, 'admin@example.com');

    expect($uri)->toStartWith('otpauth://totp/')
        ->and($uri)->toContain('issuer=sos-vault')
        ->and($uri)->toContain('admin%40example.com')
        ->and($this->svc->qrCodeDataUri($uri))->toStartWith('data:image/svg+xml;base64,');

    $this->app->detectEnvironment(fn () => 'testing');
});

// --- Enable / verify / recovery codes -----------------------------------------

it('enables 2FA and verifies a TOTP code for the user', function () {
    $secret = $this->svc->generateSecret();
    $user = User::factory()->create();

    $this->svc->enable($user, $secret, $this->svc->generateRecoveryCodes());

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($this->svc->verifyForUser($user->fresh(), currentTotp($secret)))->toBeTrue();
});

it('consumes a recovery code exactly once', function () {
    $secret = $this->svc->generateSecret();
    $codes = $this->svc->generateRecoveryCodes();
    $user = User::factory()->create();
    $this->svc->enable($user, $secret, $codes);

    // First use of a recovery code succeeds; the same code then fails.
    expect($this->svc->verifyForUser($user->fresh(), $codes[0]))->toBeTrue()
        ->and($this->svc->verifyForUser($user->fresh(), $codes[0]))->toBeFalse()
        // a different, unused code still works
        ->and($this->svc->verifyForUser($user->fresh(), $codes[1]))->toBeTrue();
});

it('disables 2FA, clearing all secrets', function () {
    $user = User::factory()->create();
    $this->svc->enable($user, $this->svc->generateSecret(), $this->svc->generateRecoveryCodes());

    $this->svc->disable($user);

    $user->refresh();
    expect($user->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull();
});

// --- Audit events -------------------------------------------------------------

it('records an ENABLE_2FA event when 2FA is enabled', function () {
    $user = User::factory()->create();

    $this->svc->enable($user, $this->svc->generateSecret(), $this->svc->generateRecoveryCodes());

    expect(Sysevent::where('type', 'ENABLE_2FA')->where('owner', $user->id)->exists())->toBeTrue();
});

it('records a DISABLE_2FA event only when 2FA was actually enabled', function () {
    $enabled = User::factory()->create();
    $this->svc->enable($enabled, $this->svc->generateSecret(), $this->svc->generateRecoveryCodes());
    $this->svc->disable($enabled);

    expect(Sysevent::where('type', 'DISABLE_2FA')->where('owner', $enabled->id)->exists())->toBeTrue();

    // Disabling an account that never had 2FA is a no-op — no event.
    $never = User::factory()->create();
    $this->svc->disable($never);

    expect(Sysevent::where('type', 'DISABLE_2FA')->where('owner', $never->id)->exists())->toBeFalse();
});

it('emits ENABLE_2FA / DISABLE_2FA (not CHG_PASS) through the security settings page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    // Drive the real enrollment flow: start setup, then confirm with a valid code.
    $component = Volt::test('settings.security')->call('startTwoFactorSetup');
    $secret = (string) session('2fa_pending_secret');

    $component->set('twoFactorCode', currentTotp($secret))->call('confirmTwoFactorSetup');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and(Sysevent::where('type', 'ENABLE_2FA')->where('owner', $user->id)->exists())->toBeTrue()
        // The old mislabeled CHG_PASS event must no longer be produced for 2FA.
        ->and(Sysevent::where('type', 'CHG_PASS')->where('owner', $user->id)->exists())->toBeFalse();

    $component->call('disableTwoFactor');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and(Sysevent::where('type', 'DISABLE_2FA')->where('owner', $user->id)->exists())->toBeTrue();
});

// --- Break-glass CLI ----------------------------------------------------------

it('break-glass command disables 2FA for a locked-out account', function () {
    $user = User::factory()->create(['email' => 'locked@example.com']);
    app(TwoFactorService::class)->enable($user, $this->svc->generateSecret(), $this->svc->generateRecoveryCodes());

    $this->artisan('2fa:disable', ['user' => 'locked@example.com'])
        ->assertExitCode(0);

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

// --- RequireTwoFactor middleware ---------------------------------------------

it('forces admins without 2FA onto the security settings page', function () {
    requireAdminTwoFactor();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get('/notifications')
        ->assertRedirect('/settings/security');
});

it('lets admins without 2FA reach the security page to enrol (no loop)', function () {
    requireAdminTwoFactor();
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)->get('/settings/security')->assertOk();
});

it('does not force 2FA on non-admin users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/notifications')->assertOk();
});

it('challenges a 2FA-enabled user whose session has not passed', function () {
    $user = User::factory()->create();
    $this->svc->enable($user, $this->svc->generateSecret(), $this->svc->generateRecoveryCodes());

    $this->actingAs($user->fresh())->get('/notifications')
        ->assertRedirect('/two-factor-challenge');
});

it('lets a 2FA-enabled user through once the session is marked passed', function () {
    $user = User::factory()->create();
    $this->svc->enable($user, $this->svc->generateSecret(), $this->svc->generateRecoveryCodes());

    $this->actingAs($user->fresh())
        ->withSession(['2fa_passed' => true])
        ->get('/notifications')->assertOk();
});

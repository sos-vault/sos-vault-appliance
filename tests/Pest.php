<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;
use Wave\Setting;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');

// ---------------------------------------------------------------------------
// Global safety net: ensure vaultsDisabled is ALWAYS 'TRUE' before every test
// so that tests which fire Auth::login() (e.g. the email verification route)
// never trigger real LUKS vault creation.  Vault lifecycle tests override this
// inside their own setup functions/test bodies when they need real OS ops.
//
// Also force product.type='saas' so the appliance branch (which ships with
// 'appliance' in config/product.php) does not trip the Sprint 5 Step B seat
// guard inside User::creating() during unrelated tests. Appliance-specific
// tests under tests/Feature/Appliance/ flip this to 'appliance' explicitly.
// ---------------------------------------------------------------------------
pest()->beforeEach(function () {
    Config::set('app.vaultsDisabled', 'TRUE');
    Config::set('product.type', 'saas');
})->in('Feature', 'Unit');

// ---------------------------------------------------------------------------
// Mandatory admin two-factor is ON by default in production, which would
// redirect every admin HTTP test to the enrolment page. Switch it OFF for the
// suite; the dedicated 2FA tests flip it back ON to exercise enforcement.
// ---------------------------------------------------------------------------
pest()->beforeEach(function () {
    Setting::updateOrCreate(
        ['key' => 'auth.two_factor_required_for_admins'],
        ['display_name' => 'auth.two_factor_required_for_admins', 'value' => '0', 'type' => 'text', 'order' => 0]
    );
    Cache::forget('wave_settings');
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

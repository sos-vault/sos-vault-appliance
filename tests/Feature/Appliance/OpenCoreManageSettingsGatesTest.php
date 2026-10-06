<?php

/**
 * Open-core gates on ManageSettings sections.
 *
 * Four sections flip based on license state:
 *   - "Authentication" — visible on SaaS or licensed appliance only.
 *   - "AI Assistant"    — visible on SaaS or licensed appliance only.
 *   - "Appliance Vaults" — visible on licensed appliance only.
 *   - "ServiceNow"      — visible on SaaS only (feature not implemented on
 *     self-hosted yet).
 *
 * "Mail" stays visible everywhere (admin needs SMTP for password reset on
 * unlicensed installs).
 */

use App\Filament\Pages\ManageSettings;
use App\Models\LocalLicense;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
});

function installActiveLicenseForSettings(): LocalLicense
{
    return LocalLicense::create([
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
}

it('hides license-gated sections on unlicensed appliance', function () {
    config(['product.type' => 'appliance']);

    Livewire::test(ManageSettings::class)
        ->assertDontSee('Authentication')
        ->assertDontSee('AI Assistant')
        ->assertDontSee('Appliance Vaults')
        ->assertDontSee('ServiceNow')
        ->assertSee('Mail');
});

it('shows license-gated sections on licensed appliance', function () {
    config(['product.type' => 'appliance']);
    installActiveLicenseForSettings();

    Livewire::test(ManageSettings::class)
        ->assertSee('Authentication')
        ->assertSee('AI Assistant')
        ->assertSee('Appliance Vaults')
        ->assertDontSee('ServiceNow')
        ->assertSee('Mail');
});

it('hides license-gated sections again after the license expires', function () {
    config(['product.type' => 'appliance']);
    installActiveLicenseForSettings();

    Livewire::test(ManageSettings::class)
        ->assertSee('AI Assistant')
        ->assertSee('Appliance Vaults');

    LocalLicense::query()->update(['expires_at' => now()->subDay()]);

    Livewire::test(ManageSettings::class)
        ->assertDontSee('AI Assistant')
        ->assertDontSee('Appliance Vaults');
});

it('shows the SaaS-only set on the SaaS build but hides Appliance Vaults', function () {
    config(['product.type' => 'saas']);

    Livewire::test(ManageSettings::class)
        ->assertSee('Authentication')
        ->assertSee('AI Assistant')
        ->assertSee('ServiceNow')
        ->assertSee('Mail')
        ->assertDontSee('Appliance Vaults');
});

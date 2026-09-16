<?php

/**
 * The Admin panel welcome widget's call-to-action says "Go to App" and links to
 * the application dashboard (/dashboard), not the old "Visit your Site" → "/".
 * Applies to SaaS and appliance (licensed or not) alike.
 */

use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Wave\Widgets\WelcomeWidget;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    Filament::setCurrentPanel('admin');

    $admin = User::factory()->create(['email_verified_at' => now(), 'verified' => 1]);
    $admin->syncRoles(['admin']);
    $this->actingAs($admin);
});

it('shows "Go to App" linking to the dashboard instead of "Visit your Site"', function () {
    Livewire::test(WelcomeWidget::class)
        ->assertSee('Go to App')
        ->assertDontSee('Visit your Site')
        ->assertSeeHtml('href="/dashboard"');
});

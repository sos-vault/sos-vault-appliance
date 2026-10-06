<?php

use App\Filament\Pages\ManageModules;
use App\Jobs\DownloadAiModelJob;
use App\Models\Module;
use App\Models\User;
use App\Services\ModelProvisionService;
use App\Services\PackageManager;
use Database\Seeders\RolesTableSeeder;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

// ---------------------------------------------------------------------------
// Access control
// ---------------------------------------------------------------------------

it('redirects guests away from the manage-modules page', function () {
    $this->get('/admin/manage-modules')->assertRedirect();
});

it('denies access to non-admin users', function () {
    $this->actingAs(User::factory()->create());
    $this->get('/admin/manage-modules')->assertForbidden();
});

// ---------------------------------------------------------------------------
// Installed packages list
// ---------------------------------------------------------------------------

it('shows installed modules in the page', function () {
    Module::factory()->create(['name' => 'My Cool Module', 'package_type' => 'module']);

    $this->actingAs($this->admin);

    $this->get('/admin/manage-modules')->assertSee('My Cool Module');
});

it('shows a placeholder when no packages are installed', function () {
    $this->actingAs($this->admin);

    $this->get('/admin/manage-modules')->assertSee('No packages installed yet');
});

// ---------------------------------------------------------------------------
// Toggle enabled
// ---------------------------------------------------------------------------

it('toggles a module from enabled to disabled', function () {
    $module = Module::factory()->create(['is_enabled' => true]);

    $this->actingAs($this->admin);

    Livewire::test(ManageModules::class)
        ->call('toggleEnabled', $module->id);

    expect($module->fresh()->is_enabled)->toBeFalse();
});

it('toggles a module from disabled to enabled', function () {
    $module = Module::factory()->disabled()->create();

    $this->actingAs($this->admin);

    Livewire::test(ManageModules::class)
        ->call('toggleEnabled', $module->id);

    expect($module->fresh()->is_enabled)->toBeTrue();
});

it('does not toggle is_enabled for patch packages', function () {
    $module = Module::factory()->patch()->create(['is_enabled' => true]);

    $this->actingAs($this->admin);

    Livewire::test(ManageModules::class)
        ->call('toggleEnabled', $module->id);

    expect($module->fresh()->is_enabled)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Remove
// ---------------------------------------------------------------------------

it('removes a module record via removeModule', function () {
    $module = Module::factory()->create();

    $mockManager = Mockery::mock(PackageManager::class);
    $mockManager->shouldReceive('remove')->once()->with(Mockery::on(fn ($m) => $m->id === $module->id));
    app()->instance(PackageManager::class, $mockManager);

    $this->actingAs($this->admin);

    Livewire::test(ManageModules::class)
        ->call('removeModule', $module->id);

    Notification::assertNotified();
});

it('shows a danger notification when remove fails', function () {
    $module = Module::factory()->create();

    $mockManager = Mockery::mock(PackageManager::class);
    $mockManager->shouldReceive('remove')->andThrow(new RuntimeException('disk full'));
    app()->instance(PackageManager::class, $mockManager);

    $this->actingAs($this->admin);

    Livewire::test(ManageModules::class)
        ->call('removeModule', $module->id);

    Notification::assertNotified('Removal failed');
});

// ---------------------------------------------------------------------------
// Install notification
// ---------------------------------------------------------------------------

it('shows an error notification when installPackage is called without a file', function () {
    $this->actingAs($this->admin);

    Livewire::test(ManageModules::class)
        ->set('data.archive', null)
        ->call('installPackage');

    Notification::assertNotified('No file uploaded');
});

// ---------------------------------------------------------------------------
// AI model section (top section)
// ---------------------------------------------------------------------------

it('shows the AI assistant model section with a download button when the model is missing', function () {
    $mock = Mockery::mock(ModelProvisionService::class);
    $mock->shouldReceive('isInstalled')->andReturn(false);
    app()->instance(ModelProvisionService::class, $mock);

    $this->actingAs($this->admin);

    $this->get('/admin/manage-modules')
        ->assertSee('AI Assistant Model')
        ->assertSee('Linux in general')
        ->assertSee('analyse sosreport data')
        ->assertSee('Download AI model');
});

it('marks the AI model as installed when the model is present', function () {
    $mock = Mockery::mock(ModelProvisionService::class);
    $mock->shouldReceive('isInstalled')->andReturn(true);
    app()->instance(ModelProvisionService::class, $mock);

    $this->actingAs($this->admin);

    $this->get('/admin/manage-modules')->assertSee('AI model installed');
});

it('dispatches the download job when the AI model download is started', function () {
    Bus::fake();
    Cache::forget(DownloadAiModelJob::STATE_CACHE_KEY);

    $mock = Mockery::mock(ModelProvisionService::class);
    $mock->shouldReceive('isInstalled')->andReturn(false);
    app()->instance(ModelProvisionService::class, $mock);

    $this->actingAs($this->admin);

    Livewire::test(ManageModules::class)
        ->call('startAiModelDownload');

    Bus::assertDispatched(DownloadAiModelJob::class);
    Notification::assertNotified('AI model download started');
    expect(DownloadAiModelJob::currentState()['status'] ?? null)->toBe('downloading');
});

it('does not dispatch a second download while one is already running', function () {
    Bus::fake();
    DownloadAiModelJob::putState(['status' => 'downloading', 'percent' => 30]);

    $mock = Mockery::mock(ModelProvisionService::class);
    $mock->shouldReceive('isInstalled')->andReturn(false);
    app()->instance(ModelProvisionService::class, $mock);

    $this->actingAs($this->admin);

    Livewire::test(ManageModules::class)
        ->call('startAiModelDownload');

    Bus::assertNotDispatched(DownloadAiModelJob::class);
});

// ---------------------------------------------------------------------------
// Open-core gating: the page (and its AI model download) must be reachable on
// UNLICENSED appliances, but module install/update stays hidden until licensed.
// ---------------------------------------------------------------------------

it('is accessible on an unlicensed appliance install', function () {
    config(['product.type' => 'appliance']); // no LocalLicense => unlicensed

    expect(ManageModules::canAccess())->toBeTrue();
});

it('hides module management but shows the AI model section when unlicensed', function () {
    config(['product.type' => 'appliance']);

    $mock = Mockery::mock(ModelProvisionService::class);
    $mock->shouldReceive('isInstalled')->andReturn(false);
    app()->instance(ModelProvisionService::class, $mock);

    $this->actingAs($this->admin);

    $this->get('/admin/manage-modules')
        ->assertSee('AI Assistant Model')
        ->assertSee('Download AI model')
        ->assertDontSee('Install / Update Package')
        ->assertDontSee('Installed Packages');
});

it('shows module management on a licensed (or SaaS) install', function () {
    // Pest forces product.type=saas => moduleManagementAvailable() is true.
    $this->actingAs($this->admin);

    $this->get('/admin/manage-modules')
        ->assertSee('Install / Update Package')
        ->assertSee('Installed Packages');
});

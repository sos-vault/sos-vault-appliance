<?php

use App\Models\LocalLicense;
use App\Models\SupportCase;
use App\Models\Tools;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
 * The Compliance tool is explicitly unlicensed: it must be visible and
 * enabled regardless of SaaS plan or appliance license state. This is the
 * regression guard for the tool-controls.blade.php $openCoreTools bypass —
 * unlike Alerts (gated via checkAccess('Alert Rules')), Compliance must
 * never hit checkAccess()/applianceLicensed() at all.
 */

function complianceGatingCase(User $user): SupportCase
{
    $vault = Vault::factory()->create([
        'owner' => $user->id,
        'group' => $user->group_id ?? $user->id,
    ]);

    return SupportCase::factory()->create([
        'vault_id' => $vault->id,
        'owner' => $user->id,
        'group' => $user->group_id ?? $user->id,
    ]);
}

function mountToolControlsForCase(User $user, SupportCase $case)
{
    return Livewire::actingAs($user)->test('tool-controls', [
        'caseid' => $case->id,
        'parent' => 'sosBrowser',
        'color' => 'primary',
    ]);
}

function complianceAction(array $tools): Action
{
    foreach ($tools as $tool) {
        if ($tool instanceof Action && $tool->getName() === 'Compliance') {
            return $tool;
        }
    }

    throw new RuntimeException('Compliance action not found in getTools() output');
}

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    Cache::flush();
});

it('is enabled for a Free-plan SaaS user with no feature grant', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Free']);
    $case = complianceGatingCase($user);

    $component = mountToolControlsForCase($user, $case);

    $action = complianceAction($component->instance()->getTools());
    expect($action->isDisabled())->toBeFalse();
});

it('is enabled for a Team-plan SaaS user', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Team']);
    $case = complianceGatingCase($user);

    $component = mountToolControlsForCase($user, $case);

    $action = complianceAction($component->instance()->getTools());
    expect($action->isDisabled())->toBeFalse();
});

it('is enabled on an unlicensed appliance', function () {
    Config::set('product.type', 'appliance');

    $user = User::factory()->create();
    $user->syncRoles(['admin']);
    $case = complianceGatingCase($user);

    $component = mountToolControlsForCase($user, $case);

    $action = complianceAction($component->instance()->getTools());
    expect($action->isDisabled())->toBeFalse();
});

it('is enabled on a licensed appliance', function () {
    Config::set('product.type', 'appliance');
    LocalLicense::create([
        'uuid' => (string) Str::uuid(),
        'customer_id' => 1,
        'machine_tokens' => ['sha256:test-host'],
        'seats' => 5,
        'features' => ['srms'],
        'status' => 'ACTIVE',
        'signed_license' => 'stub',
        'issued_at' => now(),
        'expires_at' => now()->addYear(),
        'uploaded_by' => null,
    ]);

    $user = User::factory()->create();
    $user->syncRoles(['admin']);
    $case = complianceGatingCase($user);

    $component = mountToolControlsForCase($user, $case);

    $action = complianceAction($component->instance()->getTools());
    expect($action->isDisabled())->toBeFalse();
});

it('the tool row itself is enabled and available, matching the repurposed STIG id', function () {
    $tool = Tools::query()->where('id', '80')->first();

    expect($tool)->not->toBeNull()
        ->and($tool->name)->toBe('Compliance')
        ->and((bool) $tool->enabled)->toBeTrue()
        ->and($tool->status)->toBe('available');
});

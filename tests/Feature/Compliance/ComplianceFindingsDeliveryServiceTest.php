<?php

use App\Events\SendUserEmail;
use App\Models\AnalysisRun;
use App\Models\ComplianceFinding;
use App\Models\Group;
use App\Models\SupportCase;
use App\Models\Sysevent;
use App\Models\User;
use App\Models\Vault;
use App\Notifications\ComplianceFindingsNotification;
use App\Services\Compliance\ComplianceFindingsDeliveryService;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

function findingsDeliveryVault(): array
{
    $owner = User::factory()->create();
    $owner->syncRoles(['admin']);
    $vault = Vault::create([
        'user_vault' => 'tv'.bin2hex(random_bytes(3)),
        'device' => 'unused',
        'header_file' => 'unused',
        'key' => 'unused',
        'status' => 'OPEN',
        'owner' => $owner->id,
        'group' => $owner->id,
        'perms' => 0o700,
        'shared_status' => 0,
        'current_size' => 1,
        'plan_size' => 1,
    ]);

    return ['owner' => $owner, 'vault' => $vault];
}

function findingsDeliveryCase(Vault $vault, User $owner): SupportCase
{
    return SupportCase::create([
        'case' => 'PROD-1',
        'path' => 'sosreport-x',
        'owner' => $owner->id,
        'group' => $owner->id,
        'perms' => 0o700,
        'file_id' => 1,
        'vault_id' => $vault->id,
    ]);
}

function findingsDeliveryRun(SupportCase $case): AnalysisRun
{
    return AnalysisRun::create([
        'case_id' => $case->id,
        'vault_id' => $case->vault_id,
        'dir_id' => $case->file_id,
        'status' => 'running',
        'triggered_by' => 'unpack',
        'started_at' => now(),
    ]);
}

function findingsDeliveryFinding(AnalysisRun $run, SupportCase $case, string $severity, string $status = 'fail'): ComplianceFinding
{
    return ComplianceFinding::create([
        'analysis_run_id' => $run->id,
        'case_id' => $case->id,
        'vault_id' => $case->vault_id,
        'ruleset' => 'cis',
        'rule_id' => 'cis-test-'.bin2hex(random_bytes(2)),
        'title' => 'test finding',
        'severity' => $severity,
        'status' => $status,
    ]);
}

it('notifies, emails and logs an event when HIGH/CRITICAL fail findings exist, fanning out to the whole vault group', function () {
    Notification::fake();
    Event::fake([SendUserEmail::class]);

    $env = findingsDeliveryVault();
    $manager = $env['owner'];
    $group = Group::create(['name' => 'team', 'owner_id' => $manager->id, 'vault_id' => $env['vault']->id, 'max_members' => 5]);
    $member = User::factory()->create(['group_id' => $group->id]);

    $case = findingsDeliveryCase($env['vault'], $manager);
    $run = findingsDeliveryRun($case);
    findingsDeliveryFinding($run, $case, 'CRITICAL');
    findingsDeliveryFinding($run, $case, 'HIGH');
    findingsDeliveryFinding($run, $case, 'LOW'); // must be excluded

    $result = app(ComplianceFindingsDeliveryService::class)->notifyIfNeeded($run, $case);

    expect($result['notification'])->toBeTrue()
        ->and($result['email'])->toBeTrue()
        ->and($result['event'])->toBeTrue();

    Notification::assertSentTo([$manager, $member], ComplianceFindingsNotification::class);
    Event::assertDispatched(SendUserEmail::class);
    expect(Sysevent::where('type', 'COMPLIANCE_FINDING')->where('case_id', $case->id)->exists())->toBeTrue();
});

it('does nothing when there are zero HIGH/CRITICAL fail findings', function () {
    Notification::fake();
    Event::fake([SendUserEmail::class]);

    $env = findingsDeliveryVault();
    $case = findingsDeliveryCase($env['vault'], $env['owner']);
    $run = findingsDeliveryRun($case);
    findingsDeliveryFinding($run, $case, 'LOW');
    findingsDeliveryFinding($run, $case, 'CRITICAL', 'pass'); // wrong status, excluded

    $result = app(ComplianceFindingsDeliveryService::class)->notifyIfNeeded($run, $case);

    expect($result)->toBe([]);
    Notification::assertNothingSent();
    Event::assertNotDispatched(SendUserEmail::class);
    expect(Sysevent::where('type', 'COMPLIANCE_FINDING')->where('case_id', $case->id)->exists())->toBeFalse();
});

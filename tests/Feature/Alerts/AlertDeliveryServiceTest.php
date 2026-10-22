<?php

use App\Events\SendUserEmail;
use App\Models\Alert;
use App\Models\AlertTrigger;
use App\Models\Group;
use App\Models\SupportCase;
use App\Models\Sysevent;
use App\Models\User;
use App\Models\Vault;
use App\Notifications\AlertTriggeredNotification;
use App\Services\AlertDeliveryService;
use Database\Seeders\RolesTableSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

function deliveryVault(): array
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

function deliveryCase(Vault $vault, User $owner): SupportCase
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

it('notifies every member of the vault group, plus the manager', function () {
    Notification::fake();

    $env = deliveryVault();
    $manager = $env['owner'];
    $group = Group::create(['name' => 'team', 'owner_id' => $manager->id, 'vault_id' => $env['vault']->id, 'max_members' => 5]);
    $member = User::factory()->create(['group_id' => $group->id]);

    $case = deliveryCase($env['vault'], $manager);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $manager->id,
        'name' => 'test',
        'severity' => 'WARNING',
        'type' => 'grep',
        'notify_enabled' => true,
    ]);
    $trigger = AlertTrigger::create(['alert_id' => $alert->id, 'case_id' => $case->id, 'vault_id' => $env['vault']->id]);

    $delivered = app(AlertDeliveryService::class)->deliver($alert, $trigger, $case);

    expect($delivered['notification'])->toBeTrue();
    Notification::assertSentTo([$manager, $member], AlertTriggeredNotification::class);
});

it('dispatches one SendUserEmail per configured recipient', function () {
    Event::fake([SendUserEmail::class]);

    $env = deliveryVault();
    $case = deliveryCase($env['vault'], $env['owner']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['owner']->id,
        'name' => 'test',
        'severity' => 'ERROR',
        'type' => 'grep',
        'email_enabled' => true,
        'email_addresses' => 'a@example.com, b@example.com',
    ]);
    $trigger = AlertTrigger::create(['alert_id' => $alert->id, 'case_id' => $case->id, 'vault_id' => $env['vault']->id]);

    $delivered = app(AlertDeliveryService::class)->deliver($alert, $trigger, $case);

    expect($delivered['email'])->toBeTrue();
    Event::assertDispatched(SendUserEmail::class, fn ($e) => $e->data['to'] === 'a@example.com');
    Event::assertDispatched(SendUserEmail::class, fn ($e) => $e->data['to'] === 'b@example.com');
});

it('records an ALERT_GRP Sysevent for a grep alert', function () {
    $env = deliveryVault();
    $case = deliveryCase($env['vault'], $env['owner']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['owner']->id,
        'name' => 'test',
        'severity' => 'WARNING',
        'type' => 'grep',
        'event_enabled' => true,
    ]);
    $trigger = AlertTrigger::create(['alert_id' => $alert->id, 'case_id' => $case->id, 'vault_id' => $env['vault']->id]);

    $delivered = app(AlertDeliveryService::class)->deliver($alert, $trigger, $case);

    expect($delivered['event'])->toBeTrue();
    expect(Sysevent::where('type', 'ALERT_GRP')->where('case_id', $case->id)->exists())->toBeTrue();
});

it('records an ALERT_DIFF Sysevent for a diff alert', function () {
    $env = deliveryVault();
    $case = deliveryCase($env['vault'], $env['owner']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['owner']->id,
        'name' => 'test',
        'severity' => 'WARNING',
        'type' => 'diff',
        'event_enabled' => true,
    ]);
    $trigger = AlertTrigger::create(['alert_id' => $alert->id, 'case_id' => $case->id, 'vault_id' => $env['vault']->id]);

    app(AlertDeliveryService::class)->deliver($alert, $trigger, $case);

    expect(Sysevent::where('type', 'ALERT_DIFF')->where('case_id', $case->id)->exists())->toBeTrue();
});

it('sends the Slack contact point when licensed (SaaS)', function () {
    $mock = new MockHandler([new Response(200, [], 'ok')]);
    app()->instance(Client::class, new Client(['handler' => HandlerStack::create($mock)]));

    $env = deliveryVault();
    $case = deliveryCase($env['vault'], $env['owner']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['owner']->id,
        'name' => 'test',
        'severity' => 'CRITICAL',
        'type' => 'grep',
        'contact_point_enabled' => true,
        'contact_point_destination' => 'slack',
        'contact_point_webhook_url' => 'https://hooks.slack.com/services/T00/B00/xyz',
    ]);
    $trigger = AlertTrigger::create(['alert_id' => $alert->id, 'case_id' => $case->id, 'vault_id' => $env['vault']->id]);

    $delivered = app(AlertDeliveryService::class)->deliver($alert, $trigger, $case);

    expect($delivered['contact_point'])->toBeTrue();
});

it('skips the Slack contact point on an unlicensed appliance', function () {
    Config::set('product.type', 'appliance');

    $mock = new MockHandler([new Response(200, [], 'ok')]);
    app()->instance(Client::class, new Client(['handler' => HandlerStack::create($mock)]));

    $env = deliveryVault();
    $case = deliveryCase($env['vault'], $env['owner']);

    $alert = Alert::create([
        'vault_id' => $env['vault']->id,
        'user_id' => $env['owner']->id,
        'name' => 'test',
        'severity' => 'CRITICAL',
        'type' => 'grep',
        'contact_point_enabled' => true,
        'contact_point_destination' => 'slack',
        'contact_point_webhook_url' => 'https://hooks.slack.com/services/T00/B00/xyz',
    ]);
    $trigger = AlertTrigger::create(['alert_id' => $alert->id, 'case_id' => $case->id, 'vault_id' => $env['vault']->id]);

    $delivered = app(AlertDeliveryService::class)->deliver($alert, $trigger, $case);

    expect($delivered['contact_point'])->toBeFalse();
});

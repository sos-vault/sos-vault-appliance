<?php

use App\Livewire\AlertsTable;
use App\Models\Alert;
use App\Models\User;
use App\Models\Vault;
use Database\Seeders\RolesTableSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->syncRoles(['admin']);
    $this->actingAs($this->admin);

    $this->vault = Vault::create([
        'user_vault' => 'tv'.bin2hex(random_bytes(3)),
        'device' => 'unused',
        'header_file' => 'unused',
        'key' => 'unused',
        'status' => 'OPEN',
        'owner' => $this->admin->id,
        'group' => $this->admin->id,
        'perms' => 0o700,
        'shared_status' => 0,
        'current_size' => 1,
        'plan_size' => 1,
    ]);
});

it('creates a grep alert through the table\'s CreateAction', function () {
    Livewire::test(AlertsTable::class, ['vid' => $this->vault->id, 'did' => 1, 'caseid' => 1])
        ->callTableAction('create', data: [
            'name' => 'root login disabled',
            'severity' => 'WARNING',
            'type' => 'grep',
            'grep_path' => 'etc/ssh/sshd_config',
            'grep_regex' => 'PermitRootLogin\s+no',
            'grep_match_mode' => 'found',
        ])
        ->assertHasNoTableActionErrors();

    expect(Alert::where('name', 'root login disabled')->where('vault_id', $this->vault->id)->exists())->toBeTrue();
});

it('creates a levels alert requiring a mount path for disk metric', function () {
    Livewire::test(AlertsTable::class, ['vid' => $this->vault->id, 'did' => 1, 'caseid' => 1])
        ->callTableAction('create', data: [
            'name' => 'disk full',
            'severity' => 'ERROR',
            'type' => 'levels',
            'levels_metric' => 'disk',
            'levels_threshold' => 90,
            'levels_mount_path' => '/',
        ])
        ->assertHasNoTableActionErrors();

    $alert = Alert::where('name', 'disk full')->first();
    expect($alert->levels_mount_path)->toBe('/');
});

it('rejects a levels alert missing the required mount path for a disk metric', function () {
    Livewire::test(AlertsTable::class, ['vid' => $this->vault->id, 'did' => 1, 'caseid' => 1])
        ->callTableAction('create', data: [
            'name' => 'disk full',
            'severity' => 'ERROR',
            'type' => 'levels',
            'levels_metric' => 'disk',
            'levels_threshold' => 90,
            'levels_mount_path' => '',
        ])
        ->assertHasTableActionErrors(['levels_mount_path' => 'required']);
});

it('rejects an invalid grep regex at save time', function () {
    Livewire::test(AlertsTable::class, ['vid' => $this->vault->id, 'did' => 1, 'caseid' => 1])
        ->callTableAction('create', data: [
            'name' => 'broken regex',
            'severity' => 'WARNING',
            'type' => 'grep',
            'grep_path' => 'etc/hostname',
            'grep_regex' => '(unclosed',
            'grep_match_mode' => 'found',
        ])
        ->assertHasTableActionErrors(['grep_regex']);
});

it('rejects a non-Slack webhook URL as an SSRF guard', function () {
    Livewire::test(AlertsTable::class, ['vid' => $this->vault->id, 'did' => 1, 'caseid' => 1])
        ->callTableAction('create', data: [
            'name' => 'slack alert',
            'severity' => 'CRITICAL',
            'type' => 'grep',
            'grep_path' => 'etc/hostname',
            'grep_regex' => 'host',
            'grep_match_mode' => 'found',
            'contact_point_enabled' => true,
            'contact_point_destination' => 'slack',
            'contact_point_webhook_url' => 'https://evil.example.com/steal',
        ])
        ->assertHasTableActionErrors(['contact_point_webhook_url']);
});

it('persists an encrypted Slack webhook URL and decrypts it back through the model accessor', function () {
    Livewire::test(AlertsTable::class, ['vid' => $this->vault->id, 'did' => 1, 'caseid' => 1])
        ->callTableAction('create', data: [
            'name' => 'slack alert',
            'severity' => 'CRITICAL',
            'type' => 'grep',
            'grep_path' => 'etc/hostname',
            'grep_regex' => 'host',
            'grep_match_mode' => 'found',
            'contact_point_enabled' => true,
            'contact_point_destination' => 'slack',
            'contact_point_webhook_url' => 'https://hooks.slack.com/services/T00/B00/xyz',
        ])
        ->assertHasNoTableActionErrors();

    $alert = Alert::where('name', 'slack alert')->first();

    expect($alert->contact_point_webhook_url_encrypted)->not->toBeNull();
    expect($alert->contact_point_webhook_url_encrypted)->not->toContain('hooks.slack.com');
    expect($alert->contact_point_webhook_url)->toBe('https://hooks.slack.com/services/T00/B00/xyz');
});

it('creates an alert with email delivery enabled and valid recipients', function () {
    Livewire::test(AlertsTable::class, ['vid' => $this->vault->id, 'did' => 1, 'caseid' => 1])
        ->callTableAction('create', data: [
            'name' => 'email alert',
            'severity' => 'WARNING',
            'type' => 'grep',
            'grep_path' => 'etc/hostname',
            'grep_regex' => 'host',
            'grep_match_mode' => 'found',
            'email_enabled' => true,
            'email_addresses' => 'a@example.com, b@example.com',
        ])
        ->assertHasNoTableActionErrors();

    $alert = Alert::where('name', 'email alert')->first();
    expect($alert->email_addresses)->toBe('a@example.com, b@example.com');
});

it('rejects an alert with an invalid email address in the recipients list', function () {
    Livewire::test(AlertsTable::class, ['vid' => $this->vault->id, 'did' => 1, 'caseid' => 1])
        ->callTableAction('create', data: [
            'name' => 'bad email alert',
            'severity' => 'WARNING',
            'type' => 'grep',
            'grep_path' => 'etc/hostname',
            'grep_regex' => 'host',
            'grep_match_mode' => 'found',
            'email_enabled' => true,
            'email_addresses' => 'not-an-email',
        ])
        ->assertHasTableActionErrors(['email_addresses']);
});

it('deletes an alert through the table\'s DeleteAction', function () {
    $alert = Alert::create([
        'vault_id' => $this->vault->id,
        'user_id' => $this->admin->id,
        'name' => 'to delete',
        'severity' => 'INFO',
        'type' => 'grep',
        'grep_path' => 'etc/hostname',
        'grep_regex' => 'x',
        'grep_match_mode' => 'found',
    ]);

    Livewire::test(AlertsTable::class, ['vid' => $this->vault->id, 'did' => 1, 'caseid' => 1])
        ->callTableAction('delete', $alert);

    expect(Alert::find($alert->id))->toBeNull();
});

it('lists only alerts belonging to this vault', function () {
    $otherOwner = User::factory()->create();
    $other = Vault::create([
        'user_vault' => 'tvother'.bin2hex(random_bytes(3)),
        'device' => 'unused',
        'header_file' => 'unused',
        'key' => 'unused',
        'status' => 'OPEN',
        'owner' => $otherOwner->id,
        'group' => $otherOwner->id,
        'perms' => 0o700,
        'shared_status' => 0,
        'current_size' => 1,
        'plan_size' => 1,
    ]);

    $mine = Alert::create([
        'vault_id' => $this->vault->id, 'user_id' => $this->admin->id, 'name' => 'mine',
        'severity' => 'INFO', 'type' => 'grep', 'grep_path' => 'a', 'grep_regex' => 'a', 'grep_match_mode' => 'found',
    ]);
    Alert::create([
        'vault_id' => $other->id, 'user_id' => $this->admin->id, 'name' => 'not mine',
        'severity' => 'INFO', 'type' => 'grep', 'grep_path' => 'a', 'grep_regex' => 'a', 'grep_match_mode' => 'found',
    ]);

    Livewire::test(AlertsTable::class, ['vid' => $this->vault->id, 'did' => 1, 'caseid' => 1])
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords(Alert::where('name', 'not mine')->get());
});

it('pre-fills the grep and diff path fields from the query-string prefill path', function () {
    $instance = Livewire::test(AlertsTable::class, [
        'vid' => $this->vault->id,
        'did' => 1,
        'caseid' => 1,
        'prefillPath' => 'etc/sudoers',
    ])->instance();

    expect($instance->prefillPath)->toBe('etc/sudoers');
});

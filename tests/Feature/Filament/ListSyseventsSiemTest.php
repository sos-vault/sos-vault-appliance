<?php

// Load namespace-level getSvaultKey stubs so App\Services calls resolve to a
// deterministic 32-byte test key instead of the Linux kernel keyring.
require_once __DIR__.'/../../Support/SvaultKeyStub.php';

use App\Filament\Resources\Sysevents\Pages\ListSysevents;
use App\Jobs\ForwardEventToSiem;
use App\Models\Sysevent;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Wave\Setting;

beforeEach(function () {
    // Event Log + SIEM are available on the SaaS build.
    config(['product.type' => 'saas']);
    $this->seed(RolesTableSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
    $this->actingAs($this->admin);
});

function storeSiemSetting(string $key, string $plain): void
{
    Setting::updateOrCreate(
        ['key' => $key],
        [
            'display_name' => $key,
            'value' => (new Encrypter(key: str_repeat('T', 32), cipher: config('app.cipher')))->encrypt($plain),
            'type' => 'text',
            'order' => 0,
        ]
    );
}

it('reports siemEnabled=false when no SIEM is configured', function () {
    $page = Livewire::test(ListSysevents::class)->instance();

    expect($page->siemEnabled())->toBeFalse();
});

it('reports siemEnabled=true once a SIEM is enabled and configured', function () {
    storeSiemSetting('siem.enabled', '1');
    storeSiemSetting('siem.host', '127.0.0.1');
    storeSiemSetting('siem.port', '514');

    $page = Livewire::test(ListSysevents::class)->instance();

    expect($page->siemEnabled())->toBeTrue();
});

it('siemForwardResult returns and caches the delivery trace for a record', function () {
    storeSiemSetting('siem.enabled', '1');
    storeSiemSetting('siem.host', '127.0.0.1');
    storeSiemSetting('siem.port', '9');
    storeSiemSetting('siem.protocol', 'udp');

    $user = User::factory()->create();
    $event = Sysevent::create([
        'owner' => $user->id,
        'group' => $user->id,
        'status' => 'SUCCESS',
        'type' => 'LOGIN',
        'class' => 'ACTIVITY',
        'payload' => json_encode(['x' => 1]),
    ]);

    $page = Livewire::test(ListSysevents::class)->instance();

    $result = $page->siemForwardResult($event);

    expect($result['ok'])->toBeTrue();
    // Cached under the event id so a table poll never re-sends.
    expect($page->siemForwardTraces)->toHaveKey($event->id);
});

it('bulk "Send to SIEM" dispatches a forward job per selected event', function () {
    storeSiemSetting('siem.enabled', '1');
    storeSiemSetting('siem.host', '127.0.0.1');
    storeSiemSetting('siem.port', '514');
    Queue::fake();

    $user = User::factory()->create();
    $events = collect(range(1, 3))->map(fn ($i) => Sysevent::create([
        'owner' => $user->id,
        'group' => $user->id,
        'status' => 'SUCCESS',
        'type' => 'LOGIN',
        'class' => 'ACTIVITY',
        'payload' => json_encode(['i' => $i]),
    ]));

    Livewire::test(ListSysevents::class)
        ->callTableBulkAction('sendToSiem', $events->pluck('id')->all());

    Queue::assertPushed(ForwardEventToSiem::class, 3);
});

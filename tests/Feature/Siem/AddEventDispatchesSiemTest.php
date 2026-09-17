<?php

// sendSyslogEvent() → SiemForwarder::isEnabled() → siemConfig() decrypts the
// stored settings with the svault0 key, so the stub is required here.
require_once __DIR__.'/../../Support/SvaultKeyStub.php';

use App\Jobs\ForwardEventToSiem;
use App\Models\User;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Queue;
use Wave\Setting;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
    $this->user = User::factory()->create();
});

function enableSiem(): void
{
    $enc = new Encrypter(key: str_repeat('T', 32), cipher: config('app.cipher'));
    foreach (['siem.enabled' => '1', 'siem.host' => 'siem.example.com', 'siem.port' => '514'] as $key => $plain) {
        Setting::updateOrCreate(
            ['key' => $key],
            ['display_name' => $key, 'value' => $enc->encrypt($plain), 'type' => 'text', 'order' => 0]
        );
    }
}

it('dispatches the SIEM forwarding job for every event when a SIEM is enabled', function () {
    Queue::fake();
    enableSiem();

    addEvent(['k' => 'v'], 'LOGIN', 'SUCCESS', 'ACTIVITY', 0, 0, $this->user->id, $this->user->id);

    Queue::assertPushed(ForwardEventToSiem::class, 1);
});

it('does not dispatch the SIEM job when no SIEM is configured', function () {
    Queue::fake();

    addEvent(['k' => 'v'], 'LOGIN', 'SUCCESS', 'ACTIVITY', 0, 0, $this->user->id, $this->user->id);

    Queue::assertNotPushed(ForwardEventToSiem::class);
});

it('does not dispatch the SIEM job when forwarding is disabled', function () {
    Queue::fake();

    $enc = new Encrypter(key: str_repeat('T', 32), cipher: config('app.cipher'));
    Setting::updateOrCreate(
        ['key' => 'siem.enabled'],
        ['display_name' => 'siem.enabled', 'value' => $enc->encrypt('0'), 'type' => 'text', 'order' => 0]
    );

    addEvent(['k' => 'v'], 'LOGIN', 'SUCCESS', 'ACTIVITY', 0, 0, $this->user->id, $this->user->id);

    Queue::assertNotPushed(ForwardEventToSiem::class);
});

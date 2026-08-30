<?php

// Needed for SiemForwarder::isEnabled() → siemConfig() → SiemSettingsService,
// which decrypts settings with the svault0 key.
require_once __DIR__.'/../../Support/SvaultKeyStub.php';

use App\Models\LocalLicense;
use App\Models\Sysevent;
use App\Models\User;
use App\Services\SiemForwarder;
use Database\Seeders\RolesTableSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use Wave\Setting;

beforeEach(function () {
    $this->seed(RolesTableSeeder::class);
});

function siemForwarderEncrypter(): Encrypter
{
    return new Encrypter(key: str_repeat('T', 32), cipher: config('app.cipher'));
}

function storeSiem(string $key, string $plain): void
{
    Setting::updateOrCreate(
        ['key' => $key],
        ['display_name' => $key, 'value' => siemForwarderEncrypter()->encrypt($plain), 'type' => 'text', 'order' => 0]
    );
}

function makeSysevent(array $overrides = []): Sysevent
{
    $user = User::factory()->create(['name' => 'Alice Analyst']);

    return Sysevent::create(array_merge([
        'owner' => $user->id,
        'group' => $user->id,
        'payload' => json_encode(['email' => 'alice@example.com']),
        'status' => 'SUCCESS',
        'ip' => '203.0.113.10',
        'iso_code' => 'US',
        'country' => 'United States',
        'state' => 'California',
        'city' => 'San Jose',
        'timezone' => 'America/Los_Angeles',
        'vault_id' => 7,
        'dir_id' => 0,
        'case_id' => 42,
        'type' => 'LOGIN',
        'class' => 'ACTIVITY',
    ], $overrides));
}

// ---------------------------------------------------------------------------
// ECS mapping
// ---------------------------------------------------------------------------

it('maps a Sysevent to an ECS document including LOGTYPE', function () {
    $event = makeSysevent();

    $ecs = app(SiemForwarder::class)->buildEcs($event);

    expect($ecs['LOGTYPE'])->toBe('sos-vault');
    expect($ecs['event']['action'])->toBe('LOGIN');
    expect($ecs['event']['outcome'])->toBe('success');
    expect($ecs['event']['module'])->toBe('sos-vault');
    expect($ecs['event']['id'])->toBe((string) $event->id);
    expect($ecs['user']['name'])->toBe('Alice Analyst');
    expect($ecs['user']['id'])->toBe((string) $event->owner);
    expect($ecs['source']['ip'])->toBe('203.0.113.10');
    expect($ecs['source']['geo']['country_iso_code'])->toBe('US');
    expect($ecs['source']['geo']['city_name'])->toBe('San Jose');
    expect($ecs['sosvault']['vault_id'])->toBe(7);
    expect($ecs['sosvault']['case_id'])->toBe(42);
    expect($ecs['sosvault']['payload'])->toBe(['email' => 'alice@example.com']);
});

it('marks a failed event outcome as failure', function () {
    $event = makeSysevent(['status' => 'FAILED', 'type' => 'ADD_VAULT']);

    $ecs = app(SiemForwarder::class)->buildEcs($event);

    expect($ecs['event']['outcome'])->toBe('failure');
});

// ---------------------------------------------------------------------------
// RFC 5424 mapping
// ---------------------------------------------------------------------------

it('builds a well-formed RFC 5424 line with the sos-vault SD element and LOGTYPE', function () {
    $event = makeSysevent();

    $line = app(SiemForwarder::class)->buildRfc5424($event);

    // <PRI>VERSION ... : local0 (16) * 8 + info (6) = 134.
    expect($line)->toStartWith('<134>1 ');
    expect($line)->toContain('sos-vault');
    expect($line)->toContain('[sosvault@32473 ');
    expect($line)->toContain('type="LOGIN"');
    expect($line)->toContain('status="SUCCESS"');
    expect($line)->toContain('vault_id="7"');
    expect($line)->toContain('case_id="42"');
    expect($line)->toContain('LOGTYPE="sos-vault"');
});

it('uses warning severity in the PRI for a failed event', function () {
    $event = makeSysevent(['status' => 'FAILED']);

    // local0 (16) * 8 + warning (4) = 132.
    expect(app(SiemForwarder::class)->buildRfc5424($event))->toStartWith('<132>1 ');
});

// ---------------------------------------------------------------------------
// isEnabled gate
// ---------------------------------------------------------------------------

it('reports disabled when no SIEM is configured', function () {
    expect(app(SiemForwarder::class)->isEnabled())->toBeFalse();
});

it('reports disabled when configured but the enable toggle is off', function () {
    storeSiem('siem.enabled', '0');
    storeSiem('siem.host', 'siem.example.com');
    storeSiem('siem.port', '514');

    expect(app(SiemForwarder::class)->isEnabled())->toBeFalse();
});

it('reports enabled when enabled with a host and port', function () {
    storeSiem('siem.enabled', '1');
    storeSiem('siem.host', 'siem.example.com');
    storeSiem('siem.port', '514');

    expect(app(SiemForwarder::class)->isEnabled())->toBeTrue();
});

// ---------------------------------------------------------------------------
// License gate — SIEM forwarding is a licensed feature on the appliance.
// (The global Pest beforeEach forces product.type=saas, under which the tests
// above exercise the SaaS-always-on path.)
// ---------------------------------------------------------------------------

function installSiemLicense(): LocalLicense
{
    return LocalLicense::create([
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
}

it('does not forward on an unlicensed appliance even when fully configured', function () {
    config(['product.type' => 'appliance']);
    storeSiem('siem.enabled', '1');
    storeSiem('siem.host', 'siem.example.com');
    storeSiem('siem.port', '514');

    expect(app(SiemForwarder::class)->isEnabled())->toBeFalse();
});

it('forwards on a licensed appliance when configured', function () {
    config(['product.type' => 'appliance']);
    installSiemLicense();
    storeSiem('siem.enabled', '1');
    storeSiem('siem.host', 'siem.example.com');
    storeSiem('siem.port', '514');

    expect(app(SiemForwarder::class)->isEnabled())->toBeTrue();
});

// ---------------------------------------------------------------------------
// test() — connectivity self-test / trace
// ---------------------------------------------------------------------------

it('test() stops at the license step on an unlicensed appliance', function () {
    config(['product.type' => 'appliance']);
    storeSiem('siem.host', '127.0.0.1');
    storeSiem('siem.port', '9');
    storeSiem('siem.protocol', 'udp');

    $result = app(SiemForwarder::class)->test();

    expect($result['ok'])->toBeFalse();
    expect($result['steps'])->toHaveCount(1);
    expect($result['steps'][0]['label'])->toBe('License');
    expect($result['steps'][0]['ok'])->toBeFalse();
});

it('test() stops at the configuration step when no host is set', function () {
    $result = app(SiemForwarder::class)->test();

    expect($result['ok'])->toBeFalse();
    expect($result['steps'][1]['label'])->toBe('Configuration');
    expect($result['steps'][1]['ok'])->toBeFalse();
});

it('test() succeeds over UDP even when the enable toggle is off', function () {
    storeSiem('siem.enabled', '0');
    storeSiem('siem.host', '127.0.0.1');
    storeSiem('siem.port', '9');
    storeSiem('siem.protocol', 'udp');
    storeSiem('siem.format', 'ecs');

    $result = app(SiemForwarder::class)->test();

    expect($result['ok'])->toBeTrue();
    expect($result['steps'])->toHaveCount(4);
    expect(collect($result['steps'])->every(fn ($s) => $s['ok']))->toBeTrue();
});

it('test() reports the transmit failure when a TCP port is closed', function () {
    storeSiem('siem.enabled', '1');
    storeSiem('siem.host', '127.0.0.1');
    storeSiem('siem.port', '1'); // not listening on the test host
    storeSiem('siem.protocol', 'tcp');

    $result = app(SiemForwarder::class)->test();

    expect($result['ok'])->toBeFalse();
    $steps = $result['steps'];
    $last = end($steps);
    expect($last['ok'])->toBeFalse();
    expect($last['detail'])->toContain('cannot connect');
});

it('frames TCP syslog as a newline-delimited message with no length prefix', function () {
    // A real loopback listener so we assert the exact bytes transmit() writes.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();
    $addr = stream_socket_get_name($server, false);
    $port = (int) substr($addr, strrpos($addr, ':') + 1);

    storeSiem('siem.enabled', '1');
    storeSiem('siem.host', '127.0.0.1');
    storeSiem('siem.port', (string) $port);
    storeSiem('siem.protocol', 'tcp');
    storeSiem('siem.format', 'rfc5424');

    app(SiemForwarder::class)->forward(makeSysevent());

    $conn = @stream_socket_accept($server, 2);
    expect($conn)->not->toBeFalse();
    $received = stream_get_contents($conn);
    fclose($conn);
    fclose($server);

    expect($received)->toStartWith('<');        // RFC 5424 PRI, not an RFC 6587 octet count
    expect($received)->toEndWith("\n");          // non-transparent, newline-delimited framing
    expect($received)->not->toMatch('/^\d+ /');  // no leading "<len> " length prefix
});

it('test() can re-send an existing event and names it in the trace', function () {
    storeSiem('siem.enabled', '1');
    storeSiem('siem.host', '127.0.0.1');
    storeSiem('siem.port', '9');
    storeSiem('siem.protocol', 'udp');
    $event = makeSysevent();

    $result = app(SiemForwarder::class)->test($event);

    expect($result['ok'])->toBeTrue();
    expect($result['steps'][2]['detail'])->toContain('#'.$event->id);
});

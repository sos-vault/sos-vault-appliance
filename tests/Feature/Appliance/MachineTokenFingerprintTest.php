<?php

/**
 * MachineTokenService host-fingerprint capture/read. The installer gathers host
 * identifiers and stores them encrypted (svault0 key) in settings; key-gen and
 * license install both read them back. SvaultKeyStub shadows getSvaultKey() so
 * LicensingPassphraseService encrypts/decrypts with a deterministic test key.
 */

require_once __DIR__.'/../../Support/SvaultKeyStub.php';

use App\Services\MachineTokenService;
use Wave\Setting;

it('stores an encrypted fingerprint and derives one namespaced token per identifier', function () {
    $svc = new MachineTokenService;
    $tokens = $svc->storeFingerprint([
        'machine_id' => 'abc123',
        'dmi_uuid' => 'UUID-1',
        'board_serial' => 'BS-1',
        'system_serial' => 'SS-1',
    ]);

    expect($tokens)->toHaveCount(4)
        ->and($svc->bindingStrength)->toBe('strong')
        ->and($svc->machineIdToken)->toBe('sha256:'.hash('sha256', 'machine_id:abc123'));

    // The value persisted to settings is ciphertext, never the plaintext id.
    $stored = Setting::where('key', MachineTokenService::FINGERPRINT_SETTING_KEY)->value('value');
    expect($stored)->not->toBeNull()
        ->and($stored)->not->toContain('abc123');

    // currentHostTokens() reads the stored fingerprint back to identical tokens.
    expect((new MachineTokenService)->currentHostTokens())->toBe($tokens);
});

it('skips empty and DMI-placeholder identifiers', function () {
    $svc = new MachineTokenService;
    $tokens = $svc->storeFingerprint([
        'machine_id' => 'mid',
        'dmi_uuid' => '00000000-0000-0000-0000-000000000000',
        'board_serial' => 'To be filled by O.E.M.',
        'system_serial' => '   ',
    ]);

    expect($tokens)->toHaveCount(1)
        ->and($svc->bindingStrength)->toBe('weak')
        ->and($svc->machineIdToken)->toBe('sha256:'.hash('sha256', 'machine_id:mid'));
});

it('derives tokens with no machine-id (machineIdToken stays null)', function () {
    $svc = new MachineTokenService;
    $tokens = $svc->tokensFromIdentifiers([
        'dmi_uuid' => 'U-1',
        'board_serial' => 'B-1',
    ]);

    expect($tokens)->toHaveCount(2)
        ->and($svc->machineIdToken)->toBeNull()
        ->and($svc->bindingStrength)->toBe('strong');
});

it('refuses to store when no usable identifier is supplied', function () {
    expect(fn () => (new MachineTokenService)->storeFingerprint([
        'machine_id' => '',
        'board_serial' => 'none',
    ]))->toThrow(RuntimeException::class, 'No usable hardware identifiers');
});

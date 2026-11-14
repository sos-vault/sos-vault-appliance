<?php

/**
 * MachineTokenService::encode()/decode() — the copy/paste license-request key.
 * Pure string transform: no host state, so these are deterministic.
 */

use App\Services\MachineTokenService;

function machineKeyService(): MachineTokenService
{
    return new MachineTokenService;
}

it('round-trips a token list through encode/decode', function () {
    $tokens = [
        'sha256:'.str_repeat('a', 64),
        'sha256:'.str_repeat('b', 64),
        'sha256:'.str_repeat('c', 64),
    ];

    $svc = machineKeyService();
    $key = $svc->encode($tokens);

    expect($key)->toStartWith('SOSV1.');
    expect($svc->decode($key))->toBe($tokens);
});

it('produces a URL-safe key with no base64 padding', function () {
    $key = machineKeyService()->encode(['sha256:'.str_repeat('d', 64)]);

    expect($key)->not->toContain('+')
        ->and($key)->not->toContain('/')
        ->and($key)->not->toContain('=');
});

it('rejects a key without the SOSV1 prefix', function () {
    expect(fn () => machineKeyService()->decode('nope.'.base64_encode('{}')))
        ->toThrow(RuntimeException::class, 'Unrecognised');
});

it('rejects a key whose payload is not valid base64', function () {
    expect(fn () => machineKeyService()->decode('SOSV1.@@@not-base64@@@'))
        ->toThrow(RuntimeException::class);
});

it('rejects a key with the wrong version', function () {
    $b64 = rtrim(strtr(base64_encode(json_encode(['v' => 2, 'tokens' => ['sha256:'.str_repeat('a', 64)]])), '+/', '-_'), '=');

    expect(fn () => machineKeyService()->decode('SOSV1.'.$b64))
        ->toThrow(RuntimeException::class, 'malformed');
});

it('rejects a key with no tokens', function () {
    $b64 = rtrim(strtr(base64_encode(json_encode(['v' => 1, 'tokens' => []])), '+/', '-_'), '=');

    expect(fn () => machineKeyService()->decode('SOSV1.'.$b64))
        ->toThrow(RuntimeException::class, 'no machine tokens');
});

it('rejects a key with a malformed token string', function () {
    $b64 = rtrim(strtr(base64_encode(json_encode(['v' => 1, 'tokens' => ['not-a-sha256']])), '+/', '-_'), '=');

    expect(fn () => machineKeyService()->decode('SOSV1.'.$b64))
        ->toThrow(RuntimeException::class, 'invalid machine token');
});

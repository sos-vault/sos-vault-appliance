<?php

use App\Http\Middleware\AbuseIp;
use App\Services\TelegramService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Build a Request whose ip() resolves to $ip.
 */
function abuseRequest(string $ip): Request
{
    return Request::create('https://example.test/.git/refs/heads/main', 'GET', server: [
        'REMOTE_ADDR' => $ip,
    ]);
}

/**
 * Mark $ip as abused for the duration of the test by seeding the cache that
 * is_abused_ip() reads from (compress=true stores IPs as integers).
 */
function markAbused(string $ip): void
{
    Config::set('abuseip.storage.compress', true);
    Cache::forever('abuse_ips', [ip2long($ip)]);
}

beforeEach(function () {
    Config::set('abuseip.whitelist', ['127.0.0.1']);
    // geoip() may hit the network/db; stub it so tests are fast and offline.
    app()->bind('geoip', fn () => new class
    {
        public function getLocation($ip)
        {
            return ['attributes' => []];
        }
    });
    // Never hit the real Telegram API during tests.
    $this->telegram = Mockery::mock(TelegramService::class);
    $this->telegram->shouldReceive('sendTelegramMessage')->byDefault();
    app()->instance(TelegramService::class, $this->telegram);
});

it('blocks an abused, non-whitelisted IP with 403', function () {
    markAbused('151.243.150.23');

    $hit = false;
    expect(fn () => (new AbuseIp)->handle(abuseRequest('151.243.150.23'), function () use (&$hit) {
        $hit = true;
    }))->toThrow(HttpException::class);

    expect($hit)->toBeFalse();
});

it('lets a whitelisted IP through even if it is on the abuse list', function () {
    markAbused('127.0.0.1');

    $hit = false;
    (new AbuseIp)->handle(abuseRequest('127.0.0.1'), function () use (&$hit) {
        $hit = true;

        return 'ok';
    });

    expect($hit)->toBeTrue();
});

it('lets a clean IP through', function () {
    Cache::forever('abuse_ips', []);

    $hit = false;
    (new AbuseIp)->handle(abuseRequest('8.8.8.8'), function () use (&$hit) {
        $hit = true;

        return 'ok';
    });

    expect($hit)->toBeTrue();
});

it('still returns 403 when logging the block throws (broken pipe)', function () {
    markAbused('151.243.150.23');

    // Simulate the production broken-pipe StreamHandler failure.
    Log::shouldReceive('warning')->andThrow(new UnexpectedValueException('Broken pipe'));

    expect(fn () => (new AbuseIp)->handle(abuseRequest('151.243.150.23'), fn () => 'ok'))
        ->toThrow(HttpException::class);
});

it('still blocks with 403 when the geoip database is missing', function () {
    markAbused('151.243.150.23');

    // Simulate a self-hosted install with no storage/app/geoip.mmdb: the
    // maxmind_database service throws when it cannot open the file.
    app()->bind('geoip', fn () => new class
    {
        public function getLocation($ip)
        {
            throw new RuntimeException('geoip.mmdb not found');
        }
    });

    expect(fn () => (new AbuseIp)->handle(abuseRequest('151.243.150.23'), fn () => 'ok'))
        ->toThrow(HttpException::class);
});

it('does not crash when the abuseip storage file is missing or corrupt', function () {
    // Missing file: self-hosted has no abuseip.json — middleware must fail open.
    Cache::forget('abuse_ips');
    Config::set('abuseip.storage.path', '/nonexistent/abuseip.json');
    expect(is_abused_ip('151.243.150.23'))->toBeFalse();

    // Corrupt file: invalid JSON => json_decode() null; must not throw TypeError.
    $corrupt = tempnam(sys_get_temp_dir(), 'abuseip');
    file_put_contents($corrupt, '{ this is not json');
    Cache::forget('abuse_ips');
    Config::set('abuseip.storage.path', $corrupt);
    expect(is_abused_ip('151.243.150.23'))->toBeFalse();

    // And the request passes through (no block) in both cases.
    $hit = false;
    (new AbuseIp)->handle(abuseRequest('151.243.150.23'), function () use (&$hit) {
        $hit = true;
    });
    expect($hit)->toBeTrue();

    @unlink($corrupt);
});

it('only logs/notifies once per IP within the throttle window', function () {
    markAbused('151.243.150.23');

    Log::shouldReceive('warning')->once();
    $this->telegram->shouldReceive('sendTelegramMessage')->once();

    foreach (range(1, 5) as $_) {
        try {
            (new AbuseIp)->handle(abuseRequest('151.243.150.23'), fn () => 'ok');
        } catch (HttpException) {
            // expected — every blocked request aborts 403
        }
    }
});

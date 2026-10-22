<?php

/**
 * Telegram is an OPTIONAL integration. The appliance ships with no bot token /
 * chat id, so the service must be a silent no-op when unconfigured — posting to
 * the bot API with an empty token returns 404 and spammed the log on every event
 * (login, IP block, vault activity, …).
 */

use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;

it('reports not configured when the api key or chat id is missing', function () {
    config(['services.telegram.api_key' => '', 'services.telegram.chat_id' => '']);
    expect((new TelegramService)->isConfigured())->toBeFalse();

    config(['services.telegram.api_key' => 'token', 'services.telegram.chat_id' => '']);
    expect((new TelegramService)->isConfigured())->toBeFalse();

    config(['services.telegram.api_key' => '', 'services.telegram.chat_id' => '123']);
    expect((new TelegramService)->isConfigured())->toBeFalse();
});

it('reports configured only when both credentials are present', function () {
    config(['services.telegram.api_key' => 'token', 'services.telegram.chat_id' => '123']);
    expect((new TelegramService)->isConfigured())->toBeTrue();
});

it('silently no-ops without making a request or logging when unconfigured', function () {
    config(['services.telegram.api_key' => '', 'services.telegram.chat_id' => '']);

    Log::spy();

    // No HTTP call is attempted (an empty token would 404), no exception, no log.
    expect((new TelegramService)->sendTelegramMessage('hello'))->toBeNull();

    Log::shouldNotHaveReceived('error');
});

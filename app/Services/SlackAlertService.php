<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

/**
 * Posts an Alert's delivery payload to a Slack incoming webhook. Built on the
 * already-installed guzzlehttp/guzzle — no new Composer dependency (not
 * spatie/laravel-slack-alerts, not laravel-notification-channels), mirroring
 * the existing best-effort, swallow-and-log outbound patterns (TelegramService,
 * SiemForwarder).
 *
 * Contact Point delivery is a licensed feature (mirrors SiemForwarder::licensed()):
 * always available on SaaS, appliance-licensed-only on the appliance.
 */
class SlackAlertService
{
    private const TIMEOUT = 5;

    public function __construct(private readonly Client $client) {}

    public function licensed(): bool
    {
        return isSaas() || applianceLicensed();
    }

    /** Send an Alert's payload to Slack. Returns true on a 2xx response. */
    public function send(string $webhookUrl, array $payload): bool
    {
        return $this->post($webhookUrl, $this->buildMessage($payload));
    }

    /** A minimal connectivity test, used by the form's Test button. */
    public function test(string $webhookUrl): bool
    {
        return $this->post($webhookUrl, [
            'text' => 'sos-vault test alert — your Slack contact point is configured correctly.',
        ]);
    }

    private function post(string $webhookUrl, array $body): bool
    {
        try {
            $response = $this->client->post($webhookUrl, [
                'json' => $body,
                'timeout' => self::TIMEOUT,
                'http_errors' => false,
            ]);

            return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
        } catch (GuzzleException $e) {
            Log::error('SlackAlertService: delivery failed: '.$e->getMessage());

            return false;
        }
    }

    private function buildMessage(array $payload): array
    {
        $severity = $payload['severity'] ?? 'INFO';
        $lines = [];
        foreach ($payload as $key => $value) {
            $lines[] = '*'.str_replace('_', ' ', ucfirst($key)).':* '.$value;
        }

        return [
            'text' => "[{$severity}] sos-vault alert: {$payload['name']}",
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => implode("\n", $lines),
                    ],
                ],
            ],
        ];
    }
}

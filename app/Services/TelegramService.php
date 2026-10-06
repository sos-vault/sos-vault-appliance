<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private $apiKey;

    private $chatId;

    private $client;

    public function __construct()
    {
        $this->apiKey = config('services.telegram.api_key');
        $this->chatId = config('services.telegram.chat_id');
        $this->client = new Client([
            'base_uri' => 'https://api.telegram.org/',
        ]);
    }

    /**
     * Telegram is an optional integration. It is only usable once BOTH a bot
     * API key and a chat id are configured; the appliance ships with neither.
     */
    public function isConfigured(): bool
    {
        return filled($this->apiKey) && filled($this->chatId);
    }

    public function sendTelegramMessage($message)
    {
        // When Telegram is not configured (no bot token / chat id — the default
        // on the appliance) the call is a silent no-op: posting to the bot API
        // with an empty token returns 404 and spams the log on every event.
        if (! $this->isConfigured()) {
            return;
        }

        static $lastCallTime = null;

        // Add a delay of 1 second between requests
        if ($lastCallTime && microtime(true) - $lastCallTime < 1) {
            usleep(1000000 - (microtime(true) - $lastCallTime) * 1000000);
        }

        $lastCallTime = microtime(true);

        try {
            // Use path-based routing so the token never appears in logged URLs
            $path = '/bot'.$this->apiKey.'/sendMessage';

            if ($message) {
                $response = $this->client->post($path, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'chat_id' => $this->chatId,
                        'text' => $message,
                    ],
                ]);
            }

            $data = json_decode($response->getBody(), true);

            return $data['choices'][0]['message']['content'] ?? 'Sorry, I could not understand your query.';
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 429) {
                $message = 'Too many requests. Please try again later.';
                Log::error($message);

                return;
            } else {
                $message = $e->getMessage();
            }
            Log::error($message);
        } catch (ConnectionException $e) {
            $message = $e->getMessage();
            Log::error($message);
        } catch (RequestException $e) {
            $message = $e->getMessage();
            Log::error($message);
        } catch (\Exception $e) {
            $message = $e->getMessage();
            Log::error($message);
        }
    }
}

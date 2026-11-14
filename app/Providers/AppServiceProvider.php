<?php

namespace App\Providers;

use App\Contracts\AiChatServiceContract;
use App\Listeners\RecordLogoutEvent;
use App\Services\AiChatService;
use App\Services\ModuleCatalog;
use Exception;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/auth/home';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiChatServiceContract::class, function () {
            $provider = config('ai.provider', 'local');
            $cfg = config("ai.{$provider}", config('ai.local'));

            return new AiChatService(
                provider: $provider,
                model: $cfg['model'],
                maxTokens: (int) config('ai.max_tokens', 1024),
                temperature: (float) config('ai.temperature', 0.3),
                injectCaseContext: (bool) config('ai.inject_case_context', true),
            );
        });

        $this->app->singleton(ModuleCatalog::class, fn () => new ModuleCatalog(base_path('modules')));
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Per-request reset of the VaultTools file metadata cache so static
        // state from a prior request on the same FPM worker can't leak.
        VaultTools::flushFileCache();

        if ($this->app->environment() == 'production') {
            $this->app['request']->server->set('HTTPS', true);
        }

        $this->setSchemaDefaultLength();
        $this->configureSocialiteFromSettings();
        $this->configurePaddleFromSettings();
        $this->configureAiFromSettings();

        Event::listen(Logout::class, RecordLogoutEvent::class);

        // Gates the authenticated UI, including its /api/vaultState + /api/userInfo
        // polling (both are in the 'web' group). Keyed per-user, so this is NOT a
        // brute-force surface — credential endpoints (login/register/token) carry
        // their own tight limits. The dashboard polls ~1/s and fires bursts of
        // Livewire refreshes on events (e.g. an upload completing), so 120/min
        // (2/s) was tripping 429 during normal use; 300/min gives headroom.
        RateLimiter::for('web', function (Request $request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });

        $descr = 'svault0';
        if (! getSvaultKey($descr) || strlen(getSvaultKey($descr)) != 32) {
            Log::error("$descr key not available");
            // send alert to admin to take urgent action
            // shall the app be turned off
        }

        Validator::extend('base64image', function ($attribute, $value, $parameters, $validator) {
            $explode = explode(',', $value);
            $allow = ['png', 'jpg', 'svg', 'jpeg'];
            $format = str_replace(
                [
                    'data:image/',
                    ';',
                    'base64',
                ],
                [
                    '', '', '',
                ],
                $explode[0]
            );

            // check file format
            if (! in_array($format, $allow)) {
                return false;
            }

            // check base64 format
            if (! preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $explode[1])) {
                return false;
            }

            return true;
        });
    }

    private function configureSocialiteFromSettings(): void
    {
        try {
            $appUrl = config('app.url');

            foreach (['google', 'facebook', 'github'] as $provider) {
                $clientId = setting("auth.{$provider}_client_id");
                $clientSecret = setting("auth.{$provider}_client_secret");

                if ($clientId && $clientSecret) {
                    Config::set("services.{$provider}.client_id", $clientId);
                    Config::set("services.{$provider}.client_secret", $clientSecret);
                    Config::set("services.{$provider}.redirect", "{$appUrl}/auth/{$provider}/callback");
                }
            }
        } catch (Exception) {
            // DB not yet available (e.g. during migrations) — skip silently
        }
    }

    private function configurePaddleFromSettings(): void
    {
        try {
            Config::set('wave.billing_provider', setting('billing.provider', 'paddle'));
            Config::set('wave.paddle.public_key', setting('billing.paddle_client_side_token', ''));
            Config::set('wave.paddle.api_key', setting('billing.paddle_api_key', ''));
            Config::set('wave.paddle.env', setting('billing.paddle_env', 'sandbox'));
            Config::set('wave.paddle.vendor', setting('billing.paddle_vendor_id', ''));
            Config::set('wave.paddle.webhook_secret', setting('billing.paddle_webhook_secret', ''));
        } catch (Exception) {
            // DB not yet available (e.g. during migrations) — skip silently
        }
    }

    private function configureAiFromSettings(): void
    {
        // Read the AI settings straight from the DB rather than through the
        // cache-backed setting() helper. setting() uses Cache::rememberForever
        // on the 'wave_settings' key, and the appliance cache store is Redis,
        // which can be transiently unavailable (e.g. under the memory pressure
        // of local CPU inference). A cache miss there throws, and because this
        // whole method was wrapped in a swallowing catch, the AI provider would
        // silently revert to the compiled default ('local') — routing every
        // request to llama.cpp regardless of the admin's provider choice, and
        // breaking the external providers. BillingSettingsServiceProvider reads
        // raw DB for exactly this reason; do the same here.
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $s = DB::table('settings')
                ->where('key', 'like', 'ai.%')
                ->pluck('value', 'key')
                ->all();

            $get = fn (string $key, $default) => (($s[$key] ?? null) !== null && $s[$key] !== '') ? $s[$key] : $default;

            $provider = $get('ai.provider', 'local');
            Config::set('ai.provider', $provider);
            Config::set('ai.local.base_url', $get('ai.local_url', 'http://172.21.21.61:8080/v1'));
            Config::set('ai.local.model', $get('ai.local_model', 'qwen2.5-1.5b-instruct'));
            Config::set('ai.ollama.base_url', $get('ai.ollama_url', 'http://localhost:11434/v1'));
            Config::set('ai.ollama.model', $get('ai.ollama_model', 'llama3.1'));
            Config::set('ai.openai.model', $get('ai.openai_model', 'gpt-4o'));
            Config::set('ai.anthropic.model', $get('ai.anthropic_model', 'claude-3-5-sonnet-20241022'));
            Config::set('ai.max_tokens', (int) $get('ai.max_tokens', 1024));
            Config::set('ai.temperature', (float) $get('ai.temperature', 0.3));
            Config::set('ai.rate_limit_per_minute', (int) $get('ai.rate_limit', 5));
            Config::set('ai.inject_case_context', (bool) $get('ai.inject_case_context', true));
            Config::set('ai.ollama_tools', (bool) $get('ai.ollama_tools', false));

            if ($provider === 'local') {
                // OpenRouter driver uses /v1/chat/completions — compatible with llama.cpp server
                Config::set('prism.providers.openrouter.url', $get('ai.local_url', 'http://172.21.21.61:8080/v1'));
                Config::set('prism.providers.openrouter.api_key', 'local');
                // CPU inference can take 60-120 s; override Prism's default 30 s timeout
                Config::set('prism.request_timeout', 180);
            } elseif ($provider === 'ollama') {
                // On-prem Ollama shares the OpenRouter driver (/v1/chat/completions).
                // Most Ollama servers ignore auth; send a non-empty token so the driver
                // still builds a valid Authorization header.
                Config::set('prism.providers.openrouter.url', $get('ai.ollama_url', 'http://localhost:11434/v1'));
                // ai.ollama_api_key is stored encrypted at rest (svault0); decrypt it here
                // rather than through the SERVICES_MAP path used for the other AI keys.
                $ollamaApiKey = $get('ai.ollama_api_key', '');
                Config::set('prism.providers.openrouter.api_key', $ollamaApiKey !== '' ? decryptSetting($ollamaApiKey) : 'ollama');
                // Self-hosted inference can also be slow; keep the generous timeout.
                Config::set('prism.request_timeout', 180);
            }
        } catch (\Throwable) {
            // DB not yet available (e.g. during migrations) — skip silently
        }
    }

    private function setSchemaDefaultLength(): void
    {
        try {
            Schema::defaultStringLength(191);
        } catch (Exception $exception) {
        }
    }
}

<?php

// Load namespace-level getSvaultKey stubs so App\Services calls resolve to a
// deterministic 32-byte test key instead of the Linux kernel keyring. Needed
// for the encrypted ai.ollama_api_key test below.
require_once __DIR__.'/../../Support/SvaultKeyStub.php';

use App\Providers\AppServiceProvider;
use App\Services\SettingsEncryptionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Re-run AppServiceProvider::configureAiFromSettings() against the current
 * DB/cache state, the same way it runs during every request boot.
 */
function bootAiSettings(): void
{
    $provider = app()->getProvider(AppServiceProvider::class);
    $method = new ReflectionMethod($provider, 'configureAiFromSettings');
    $method->setAccessible(true);
    $method->invoke($provider);
}

it('selects the AI provider from the DB even when the settings cache is stale', function () {
    // Admin saved OpenAI to the settings table (raw insert bypasses the model
    // event so it does NOT invalidate the cache — simulating a save the cache
    // backend failed to see, or a Redis hiccup on the read side).
    DB::table('settings')->updateOrInsert(
        ['key' => 'ai.provider'],
        ['display_name' => 'ai.provider', 'value' => 'openai', 'type' => 'text', 'order' => 0]
    );

    // The cache-backed setting() helper still holds the old 'local' value.
    Cache::forever('wave_settings', ['ai.provider' => 'local']);

    // Start from the compiled default and boot the AI config.
    Config::set('ai.provider', 'local');
    bootAiSettings();

    // Provider selection must reflect the DB (openai), NOT the stale cache.
    expect(config('ai.provider'))->toBe('openai');
    // Sanity: the cache-backed helper would still report the stale value, which
    // is exactly why configureAiFromSettings must not depend on it.
    expect(setting('ai.provider'))->toBe('local');
});

it('applies OpenAI model/behaviour settings from the DB regardless of the cache', function () {
    DB::table('settings')->insert([
        ['key' => 'ai.provider', 'display_name' => 'ai.provider', 'value' => 'openai', 'type' => 'text', 'order' => 0],
        ['key' => 'ai.openai_model', 'display_name' => 'ai.openai_model', 'value' => 'gpt-4', 'type' => 'text', 'order' => 0],
    ]);
    Cache::forever('wave_settings', []); // empty/broken cache

    bootAiSettings();

    expect(config('ai.provider'))->toBe('openai')
        ->and(config('ai.openai.model'))->toBe('gpt-4');
});

it('falls back to the local provider when no AI provider is stored', function () {
    DB::table('settings')->where('key', 'like', 'ai.%')->delete();
    Cache::forget('wave_settings');

    bootAiSettings();

    expect(config('ai.provider'))->toBe('local');
});

it('decrypts the ollama api key from the DB into the openrouter prism config', function () {
    $cipher = app(SettingsEncryptionService::class)->encrypt('ollama-secret-key');

    DB::table('settings')->insert([
        ['key' => 'ai.provider', 'display_name' => 'ai.provider', 'value' => 'ollama', 'type' => 'text', 'order' => 0],
        ['key' => 'ai.ollama_api_key', 'display_name' => 'ai.ollama_api_key', 'value' => $cipher, 'type' => 'text', 'order' => 0],
    ]);
    Cache::forget('wave_settings');

    bootAiSettings();

    expect(config('prism.providers.openrouter.api_key'))->toBe('ollama-secret-key');
});

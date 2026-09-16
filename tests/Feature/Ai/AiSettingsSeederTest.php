<?php

use Database\Seeders\AiSettingsSeeder;
use Wave\Setting;

it('seeds the local llama.cpp defaults so the AI settings fields are not blank', function () {
    $this->seed(AiSettingsSeeder::class);

    expect(Setting::get('ai.provider'))->toBe('local');
    // The appliance llama container's static sail-network IP + the bundled GGUF.
    expect(Setting::get('ai.local_url'))->toBe('http://172.21.21.61:8080/v1');
    expect(Setting::get('ai.local_model'))->toBe('qwen2.5-1.5b-instruct');
});

it('does not seed the removed fallback-to-openai setting', function () {
    $this->seed(AiSettingsSeeder::class);

    expect(Setting::where('key', 'ai.fallback_to_openai')->exists())->toBeFalse();
});

it('never overwrites an operator-edited value on re-seed', function () {
    Setting::updateOrCreate(['key' => 'ai.local_model'], ['value' => 'custom-model', 'display_name' => 'ai.local_model', 'type' => 'text', 'order' => 0]);

    $this->seed(AiSettingsSeeder::class);

    expect(Setting::get('ai.local_model'))->toBe('custom-model');
});

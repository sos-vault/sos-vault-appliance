<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Wave\Setting;

class AiSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'ai.provider' => 'local',
            'ai.local_url' => 'http://172.21.21.61:8080/v1',
            'ai.local_model' => 'qwen2.5-1.5b-instruct',
            'ai.ollama_url' => 'http://localhost:11434/v1',
            'ai.ollama_model' => 'llama3.1',
            'ai.ollama_api_key' => '',
            'ai.ollama_tools' => '0',
            'ai.openai_model' => 'gpt-4o',
            'ai.openai_api_key' => '',
            'ai.anthropic_model' => 'claude-3-5-sonnet-20241022',
            'ai.anthropic_api_key' => '',
            'ai.max_tokens' => '512',
            'ai.temperature' => '0.3',
            'ai.rate_limit' => '5',
            'ai.inject_case_context' => '1',
        ];

        foreach ($defaults as $key => $value) {
            Setting::firstOrCreate(
                ['key' => $key],
                ['display_name' => $key, 'value' => $value, 'type' => 'text', 'order' => 0]
            );
        }
    }
}

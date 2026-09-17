<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    | Supported: "local", "openai", "anthropic"
    | Runtime value is loaded from the settings table by AppServiceProvider.
    | These are fallback defaults used only when the DB is unavailable.
    */
    'provider' => 'local',

    'local' => [
        'base_url' => 'http://172.21.21.61:8080/v1',
        'model' => 'qwen2.5-1.5b-instruct',
        'api_key' => 'local',

        // First-boot / on-demand model provisioning. The ~1.1 GB GGUF weights
        // are NOT shipped in the deb; they are downloaded from HuggingFace into
        // models/ (bind-mounted into the llama.cpp container) on first boot
        // (installer Step 10) or from the admin "Software Updates" page.
        // sha256 is verified after download; it pins the exact known-good file.
        'model_dir' => base_path('models'),
        'model_filename' => 'qwen2.5-1.5b-instruct-q4_k_m.gguf',
        'model_url' => 'https://huggingface.co/Qwen/Qwen2.5-1.5B-Instruct-GGUF/resolve/main/qwen2.5-1.5b-instruct-q4_k_m.gguf?download=true',
        'model_sha256' => '6a1a2eb6d15622bf3c96857206351ba97e1af16c30d7a74ee38970e434e9407e',
    ],

    // On-prem Ollama server (OpenAI-compatible /v1 endpoint) for customers hosting
    // their own models (DeepSeek, Llama, Qwen, …). Same Prism driver as 'local';
    // runtime values come from the settings table via AppServiceProvider.
    'ollama' => [
        'base_url' => 'http://localhost:11434/v1',
        'model' => 'llama3.1',
        'api_key' => 'ollama',
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
        'model' => 'gpt-4o',
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => 'claude-3-5-sonnet-20241022',
    ],

    'max_tokens' => 512,
    'temperature' => 0.1,
    'rate_limit_per_minute' => 5,
    'inject_case_context' => true,

    // On-prem Ollama only: let the model drive retrieval via the fetch_case_data
    // tool (agentic, multi-step) instead of the single-shot selective injection.
    // Off by default — enable only for a model known to tool-call reliably.
    'ollama_tools' => false,

    /*
    |--------------------------------------------------------------------------
    | Per-provider profiles
    |--------------------------------------------------------------------------
    | Budget + capability per provider. The local CPU model (qwen2.5-1.5b) gets
    | tight caps and current-sosreport analysis disabled; cloud models inherit
    | the generous "default" profile. Resolved via App\Services\Ai\ProviderProfile.
    |
    |   case_analysis_enabled — inject live case data / answer area 4 questions
    |   max_knowledge_chars   — total cap on injected knowledge-base text
    |   per_file_cap          — cap per injected case JSON file
    |   history_turns         — trailing chat messages kept (prefill control)
    */
    'profiles' => [
        'default' => [
            'case_analysis_enabled' => true,
            // Roomy enough for both SosVault docs (app guide ~14.6k + operator FAQ
            // ~8.1k) plus instructions (~2.7k) without truncation on cloud models
            // (the appliance points SosVault at both docs).
            'max_knowledge_chars' => 28000,
            'per_file_cap' => 4000,
            'history_turns' => 6,
        ],
        'local' => [
            'case_analysis_enabled' => false,
            'max_knowledge_chars' => 4000,
            'per_file_cap' => 1200,
            'history_turns' => 2,
        ],
        // On-prem Ollama hosts a customer-chosen model that is typically far more
        // capable than the tiny bundled CPU model, so it gets the generous "default"
        // budget with current-sosreport analysis enabled. Tune down here if the
        // self-hosted model is small.
        'ollama' => [
            'case_analysis_enabled' => true,
            'max_knowledge_chars' => 28000,
            'per_file_cap' => 4000,
            'history_turns' => 6,
        ],
    ],

    'system_prompt_path' => base_path('agent'),

    /*
    | Knowledge-base files loaded per routed intent (relative to system_prompt_path).
    | 'instructions' is always loaded; 'linux' intentionally has no doc — modern
    | models know Linux, so we save the tokens.
    */
    'knowledge' => [
        'instructions' => 'instructions.md',
        // App-usage guide first (the high-frequency need every user has), then the
        // operator/admin FAQ. Cloud models (18k budget) get both in full; the local
        // 1.5B's tight budget keeps the app guide and drops the operator FAQ — that
        // audience uses the cloud provider or the Documentation pages for admin help.
        'sos_vault' => ['kb/sos_vault.md', 'kb/sos_vault_appliance.md'],
        'sos_command' => 'kb/sos_command.md',
        'case_analysis' => 'kb/case_analysis.md',
        'plugins_lookup' => 'kb/sos_plugins.json',
    ],

    /*
    | Compact health summary produced once at parse time (DataTools::getAiDigest).
    | Always injected for case-analysis questions on capable providers.
    */
    'case_digest_file' => '.aiDigest.json',

    /*
    | DEPRECATED — superseded by the Data Catalog (config/ai_case_catalog.php) +
    | App\Services\Ai\SourceSelector, which score a question against each source's
    | own catalog text instead of a hand-maintained keyword list. No longer read
    | by CaseContextBuilder; kept only for reference / rollback. Do not extend —
    | add a source to the catalog instead.
    |
    | Topic keyword => case JSON file(s).
    */
    'topic_files' => [
        'memory' => ['.memoryData.json'],
        'swap' => ['.memoryData.json'],
        'ram' => ['.memoryData.json'],
        'oom' => ['.memoryData.json', '.logErrorsData.json'],
        'cpu' => ['.cpuData.json'],
        'load' => ['.cpuData.json', '.processesData.json'],
        'processor' => ['.cpuData.json'],
        'process' => ['.processesData.json'],
        'pid' => ['.processesData.json'],
        'fd' => ['.processesData.json', '.openFilesData.json'],
        'descriptor' => ['.processesData.json', '.openFilesData.json'],
        'disk' => ['.disksData.json'],
        'filesystem' => ['.disksData.json'],
        'mount' => ['.disksData.json'],
        'inode' => ['.disksData.json'],
        'storage' => ['.disksData.json'],
        'network' => ['.networkData.json', '.nicData.json'],
        'connection' => ['.networkData.json'],
        'socket' => ['.networkData.json', '.sockstat.json'],
        'port' => ['.networkData.json'],
        'listen' => ['.networkData.json'],
        'listening' => ['.networkData.json'],
        'tcp' => ['.networkData.json', '.sockstat.json'],
        'udp' => ['.networkData.json', '.sockstat.json'],
        'netstat' => ['.networkData.json'],
        'interface' => ['.nicData.json'],
        'nic' => ['.nicData.json'],
        'firewall' => ['.iptablesData.json'],
        'iptables' => ['.iptablesData.json'],
        'log' => ['.logErrorsData.json'],
        'error' => ['.logErrorsData.json'],
        'package' => ['.packagesData.json'],
        'packages' => ['.packagesData.json'],
        'rpm' => ['.packagesData.json'],
        'dpkg' => ['.packagesData.json'],
        'installed' => ['.packagesData.json'],
        'library' => ['.packagesData.json'],
        'openssl' => ['.packagesData.json'],
        'open files' => ['.openFilesData.json'],
        'sysctl' => ['.kparametersData.json'],
        'kernel parameter' => ['.kparametersData.json'],
        'hardware' => ['.inventoryData.json'],
        'os version' => ['.osVersion.json'],
    ],

];

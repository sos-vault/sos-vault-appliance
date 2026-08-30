<x-filament::page>
    {{-- AI Assistant Model — always available, licensed or not, so the local bot
         can be enabled before a license is applied. --}}
    <x-filament::section icon="phosphor-robot-duotone">
        <x-slot name="heading">AI Assistant Model</x-slot>
        <x-slot name="description">
            The in-app assistant (bot) relies on a local large language model (LLM) that is not bundled with
            the installation, keeping the package small. Download the model (about 1.1 GB) to enable the
            assistant. It answers questions about sos-vault, the sos command, and Linux in general, running
            entirely on this server with no data sent to any third party. Note: this bundled model does
            <strong>not</strong> analyse sosreport data. For sosreport analysis, connect a more capable
            external AI provider (model + API key) under <strong>Manage Settings → AI Assistant</strong>.
            You only need to download this once.
        </x-slot>

        @php($state = $this->aiModelDownloadState)

        @if($this->aiModelInstalled)
            <div class="flex items-center gap-2 text-sm font-medium text-success-600 dark:text-success-400">
                <x-filament::icon icon="phosphor-check-circle-duotone" class="h-5 w-5" />
                AI model installed — the local assistant is ready.
            </div>
        @elseif($this->aiModelDownloading)
            @php($percent = (int) ($state['percent'] ?? 0))
            @php($downloaded = (int) ($state['downloaded'] ?? 0))
            @php($total = (int) ($state['total'] ?? 0))
            <div wire:poll.2s>
                <div class="mb-2 flex items-center justify-between text-sm">
                    <span class="flex items-center gap-2 font-medium text-primary-600 dark:text-primary-400">
                        <x-filament::icon icon="phosphor-spinner-gap-duotone" class="h-5 w-5 animate-spin" />
                        Downloading AI model…
                    </span>
                    <span class="font-mono text-gray-600 dark:text-gray-300">{{ $percent }}%</span>
                </div>
                <div class="h-3 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                    <div
                        class="h-3 rounded-full bg-primary-600 transition-all duration-500 ease-out"
                        style="width: {{ max(2, min(100, $percent)) }}%"
                    ></div>
                </div>
                @if($total > 0)
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        {{ number_format($downloaded / 1048576, 0) }} MB of {{ number_format($total / 1048576, 0) }} MB
                    </p>
                @else
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                        Starting download… this may take several minutes on a slow connection.
                    </p>
                @endif
            </div>
        @else
            @if(($state['status'] ?? null) === 'failed')
                <div class="mb-3 rounded-lg border border-danger-300 bg-danger-50 px-3 py-2 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-950 dark:text-danger-300">
                    <x-filament::icon icon="phosphor-warning-circle-duotone" class="mr-1 inline h-4 w-4" />
                    Previous download failed: {{ $state['error'] ?? 'unknown error' }}
                </div>
            @endif
            <x-filament::button
                wire:click="startAiModelDownload"
                wire:confirm="Download the ~1.1 GB local AI model in the background? The assistant becomes available once it finishes."
                icon="phosphor-download-simple-duotone"
            >
                {{ ($state['status'] ?? null) === 'failed' ? 'Retry download' : 'Download AI model' }}
            </x-filament::button>
        @endif
    </x-filament::section>

    {{-- Module install / update — requires an active license. --}}
    @if($this->moduleManagementAvailable())
        {{ $this->form }}

        <x-filament::section>
            <x-slot name="heading">Installed Packages</x-slot>
            <x-slot name="description">Manage installed modules and patches.</x-slot>

            @if($this->installedModules->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No packages installed yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300">Name</th>
                                <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300">Type</th>
                                <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300">Version</th>
                                <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300">Author</th>
                                <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300">Enabled</th>
                                <th class="px-4 py-3 font-semibold text-gray-700 dark:text-gray-300">Installed</th>
                                <th class="px-4 py-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($this->installedModules as $module)
                                <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <td class="px-4 py-3">
                                        <div class="font-medium text-gray-900 dark:text-gray-100">{{ $module->name }}</div>
                                        @if($module->description)
                                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $module->description }}</div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <x-filament::badge :color="$module->package_type === 'module' ? 'primary' : 'warning'">
                                            {{ ucfirst($module->package_type) }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $module->version }}</td>
                                    <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $module->author ?? '—' }}</td>
                                    <td class="px-4 py-3">
                                        @if($module->package_type === 'module')
                                            <button
                                                wire:click="toggleEnabled({{ $module->id }})"
                                                wire:loading.attr="disabled"
                                                class="inline-flex items-center gap-1 text-sm font-medium
                                                    {{ $module->is_enabled
                                                        ? 'text-success-600 dark:text-success-400'
                                                        : 'text-gray-400 dark:text-gray-500' }}"
                                            >
                                                @if($module->is_enabled)
                                                    <x-filament::icon icon="phosphor-toggle-right-duotone" class="w-5 h-5" />
                                                    Enabled
                                                @else
                                                    <x-filament::icon icon="phosphor-toggle-left-duotone" class="w-5 h-5" />
                                                    Disabled
                                                @endif
                                            </button>
                                        @else
                                            <span class="text-gray-400 dark:text-gray-500 text-xs">N/A</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-gray-500 dark:text-gray-400 text-xs">
                                        {{ $module->installed_at->diffForHumans() }}
                                    </td>
                                    <td class="px-4 py-3">
                                        <x-filament::button
                                            wire:click="removeModule({{ $module->id }})"
                                            wire:confirm="Are you sure you want to remove {{ $module->name }}? This cannot be undone."
                                            color="danger"
                                            size="sm"
                                            icon="phosphor-trash-duotone"
                                        >
                                            Remove
                                        </x-filament::button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament::page>

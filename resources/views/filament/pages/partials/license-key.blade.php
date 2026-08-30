{{-- Read-only, click-to-copy license request key. Rendered as a View schema
     component inside the "Request a License" → key Section on the appliance
     Manage License page. $schemaComponent is the View component; its Livewire
     owner is the ManageLicense page, which carries the generated key. --}}
@php($licenseKey = $schemaComponent->getLivewire()->licenseKey)
<div x-data="{ copied: false }" class="flex items-start gap-2">
    <textarea
        readonly
        rows="3"
        x-ref="key"
        onclick="this.select()"
        class="flex-1 font-mono text-xs break-all rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
    >{{ $licenseKey }}</textarea>

    <x-filament::button
        type="button"
        icon="phosphor-copy-duotone"
        x-on:click="navigator.clipboard.writeText($refs.key.value); copied = true; setTimeout(() => copied = false, 2000)"
    >
        <span x-show="! copied">{{ __('licensing.request.button_copy') }}</span>
        <span x-show="copied" x-cloak>{{ __('licensing.request.button_copied') }}</span>
    </x-filament::button>
</div>

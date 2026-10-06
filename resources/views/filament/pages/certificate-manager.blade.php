<x-filament::page>
    @php($cert = $this->getCurrentCertificateData())

    <x-filament::section>
        <x-slot name="heading">{{ __('appliance.certificate.card_heading') }}</x-slot>

        @if (! $cert['available'])
            <div class="rounded bg-danger-50 dark:bg-danger-950 px-4 py-3 text-sm text-danger-700 dark:text-danger-300">
                <strong>{{ __('appliance.certificate.card_unavailable') }}</strong>
                <span class="block font-mono mt-1 text-xs break-all">{{ $cert['error'] }}</span>
            </div>
        @else
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div class="sm:col-span-2">
                    <dt class="font-medium text-gray-500 dark:text-gray-400">{{ __('appliance.certificate.field_subject') }}</dt>
                    <dd class="font-mono text-xs break-all">{{ $cert['subject'] ?: '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="font-medium text-gray-500 dark:text-gray-400">{{ __('appliance.certificate.field_issuer') }}</dt>
                    <dd class="font-mono text-xs break-all">{{ $cert['issuer'] ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">{{ __('appliance.certificate.field_expires') }}</dt>
                    <dd>
                        @if ($cert['expires_at'])
                            {{ $cert['expires_at']->format('Y-m-d') }}
                            @if ($cert['is_expiring_soon'])
                                <span class="ml-1 text-warning-600 text-xs">{{ __('appliance.certificate.expiring_soon') }}</span>
                            @endif
                        @else
                            <span class="text-gray-400">{{ __('appliance.certificate.field_unknown') }}</span>
                        @endif
                    </dd>
                </div>
            </dl>
        @endif
    </x-filament::section>

    <div class="mt-6">
        {{ $this->form }}
    </div>
</x-filament::page>

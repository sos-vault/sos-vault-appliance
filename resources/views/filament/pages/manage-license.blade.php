<x-filament::page>
    @php($info = $this->getInstalledLicenseData())

    <x-filament::section>
        <x-slot name="heading">{{ __('appliance.manage_license.card_heading') }}</x-slot>

        @if (! $info['installed'])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('appliance.manage_license.card_none') }}
            </p>
        @else
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">{{ __('appliance.manage_license.field_license_id') }}</dt>
                    <dd class="font-mono text-xs break-all">{{ $info['uuid'] }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">{{ __('appliance.manage_license.field_customer_id') }}</dt>
                    <dd>{{ $info['customer_id'] }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">{{ __('appliance.manage_license.field_status') }}</dt>
                    <dd>
                        <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium
                            {{ $info['status'] === 'ACTIVE' ? 'bg-success-100 text-success-700' : 'bg-warning-100 text-warning-700' }}">
                            @switch($info['status'])
                                @case('ACTIVE') {{ __('licensing.status.active') }} @break
                                @case('EXPIRED') {{ __('licensing.status.expired') }} @break
                                @case('REVOKED') {{ __('licensing.status.revoked') }} @break
                                @default {{ $info['status'] }}
                            @endswitch
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">{{ __('appliance.manage_license.field_seats') }}</dt>
                    <dd>{{ __('appliance.manage_license.field_seats_used', ['used' => $info['seats_used'], 'total' => $info['seats'], 'remaining' => $info['seats_remaining']]) }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">{{ __('appliance.manage_license.field_issued') }}</dt>
                    <dd>{{ $info['issued_at']->format('Y-m-d') }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500 dark:text-gray-400">{{ __('appliance.manage_license.field_expires') }}</dt>
                    <dd>
                        {{ $info['expires_at']->format('Y-m-d') }}
                        @if ($info['is_expiring_soon'])
                            <span class="ml-1 text-warning-600 text-xs">{{ __('appliance.manage_license.expiring_soon') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="font-medium text-gray-500 dark:text-gray-400">{{ __('appliance.manage_license.field_features') }}</dt>
                    <dd class="flex flex-wrap gap-1 mt-1">
                        @foreach ($info['features'] as $feature)
                            <span class="inline-flex items-center rounded bg-primary-100 text-primary-700 px-2 py-0.5 text-xs font-mono">
                                {{ $feature }}
                            </span>
                        @endforeach
                    </dd>
                </div>
            </dl>
        @endif
    </x-filament::section>

    {{-- "Request a License", the generated-key section, and "Install License"
         all render in order inside $this->form (see ManageLicense::form()). --}}
    <div class="mt-6">
        {{ $this->form }}
    </div>
</x-filament::page>

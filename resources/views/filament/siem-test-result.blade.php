{{-- SIEM connectivity trace, shared by the Manage Settings "Send test event"
     button and the Event Log "Send to SIEM" record action. $result is the array
     returned by App\Services\SiemForwarder::test(). --}}
<div class="space-y-3">
    @foreach ($result['steps'] as $step)
        <div class="flex items-start gap-3">
            <x-filament::badge :color="$step['ok'] ? 'success' : 'danger'">
                {{ $step['ok'] ? 'OK' : 'FAIL' }}
            </x-filament::badge>

            <div class="min-w-0">
                <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $step['label'] }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400 break-words">{{ $step['detail'] }}</p>
            </div>
        </div>
    @endforeach

    <div class="border-t border-gray-200 pt-3 dark:border-white/10">
        <x-filament::badge :color="$result['ok'] ? 'success' : 'danger'">
            {{ $result['ok'] ? 'Test event delivered' : 'Delivery failed — see the first FAIL above' }}
        </x-filament::badge>
    </div>
</div>

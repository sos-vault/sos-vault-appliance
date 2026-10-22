<x-app.container>
    <x-filament::section
        :collapsed="false"
        description="{{ $description }}"
        heading="{{ $title }}"
        :contained="false" >

        <x-card class="flex flex-col w-full max-w-6xl mx-auto lg:my-4 overflow-x-auto dark:border-zinc-200 rounded-lg dark:bg-zinc-900">
            <div class="flex flex-col pt-2 lg:flex-row lg:space-x-8">
                <aside class="shrink-0 pb-8 lg:pt-4 lg:pb-0 lg:w-48">
                    <nav class="flex items-start justify-start lg:flex-col lg:space-y-1 text-slate-600 dark:text-zinc-300 ">
                        <div class="px-2.5 pb-1.5 lg:block hidden leading-6 ">{{ __('nav.settings_label') }}</div>
                        <div class="flex items-center w-auto space-x-2 lg:items-stretch lg:flex-col lg:w-full lg:space-y-1 lg:space-x-0">
                            <x-settings-sidebar-link :href="route('settings.profile')" icon="phosphor-user-circle-duotone">{{ __('nav.nav_profile') }}</x-settings-sidebar-link>
                            <x-settings-sidebar-link :href="route('settings.security')" icon="phosphor-lock-duotone">{{ __('nav.nav_security') }}</x-settings-sidebar-link>
                            @if(checkAccess(auth()->user(), 'Direct Upload') || checkAccess(auth()->user(), 'API access'))
                                <x-settings-sidebar-link :href="route('settings.api')" icon="phosphor-key-duotone">{{ __('nav.nav_keys') }}</x-settings-sidebar-link>
                            @endif
                            {{-- ITSM integration is a licensed feature: hide it on an unlicensed appliance. --}}
                            @if(checkAccess(auth()->user(), 'ITSM Integration') && (isSaas() || applianceLicensed()))
                                <x-settings-sidebar-link :href="route('settings.itsm')" icon="simpleicon-jirasoftware">{{ __('nav.nav_itsm') }}</x-settings-sidebar-link>
                            @endif
                            {{-- Self-service team management is a SaaS concept; the appliance manages teams admin-side. --}}
                            @if(auth()->user()?->isTeamManager() && ! isAppliance())
                                <x-settings-sidebar-link :href="route('settings.team')" icon="phosphor-users-three-duotone">{{ __('nav.settings_team') }}</x-settings-sidebar-link>
                            @endif
                            {{--
                            @if(checkAccess(auth()->user(), 'Assistant'))
                                <x-settings-sidebar-link :href="route('settings.assistant')" icon="phosphor-robot-duotone">{{ __('nav.nav_ai_assistant') }}</x-settings-sidebar-link>
                            @endif
                            --}}
                        </div>

                        @if (! isAppliance())
                        <div class="px-2.5 pt-3.5 pb-1.5 lg:block hidden leading-6 text-slate-600 dark:text-zinc-300">{{ __('nav.billing_label') }}</div>
                        <div class="flex items-center w-full ml-2 space-x-2 lg:items-stretch lg:flex-col lg:ml-0 lg:space-y-1 lg:space-x-0">
                            <x-settings-sidebar-link :href="route('settings.subscription')" icon="phosphor-credit-card-duotone">{{ __('nav.nav_subscription') }}</x-settings-sidebar-link>
                            <x-settings-sidebar-link :href="route('settings.invoices')" icon="phosphor-invoice-duotone">{{ __('nav.nav_invoices') }}</x-settings-sidebar-link>
                        </div>
                        @endif

                    </nav>
                </aside>

                <div class="py-2 lg:px-6 lg:flex-1 lg:min-w-0 dark:bg-zinc-800">
                    {{ $slot }}
                </div>
            </div>
        </x-card>
    </x-filament::section>
</x-app.container>

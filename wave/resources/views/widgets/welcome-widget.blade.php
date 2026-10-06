@php
    $user = filament()->auth()->user();
@endphp

<x-filament-widgets::widget class="fi-account-widget">
    <x-filament::section>
        <div class="flex gap-x-3 items-center">

            <div class="flex-1">
                <h2
                    class="grid flex-1 text-base font-semibold leading-6 text-gray-950 dark:text-white"
                >
                    Welcome to the Admin panel
                </h2>

            </div>



                <x-filament::button
                    color="gray"
                    icon="heroicon-m-arrow-top-right-on-square"
                    icon-alias="panels::widgets.account.logout-button"
                    labeled-from="sm"
                    tag="a"
                    type="submit"
                    href="/dashboard"
                >
                    Go to App
                </x-filament::button>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>

<?php
use function Laravel\Folio\middleware;
use function Laravel\Folio\name;

middleware(['auth', \App\Http\Middleware\DenyOnAppliance::class]);
name('settings.invoices');
?>

<x-layouts.app>
        <div class="relative">
            <x-app.settings-layout
                :title="__('settings.invoices_title')"
                :description="__('settings.invoices_description')"
            >
                @livewire('invoices')

            </x-app.settings-layout>
        </div>
</x-layouts.app>

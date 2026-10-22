<?php

use App\Models\Vault;
use App\Providers\VaultTools;
use Filament\Notifications\Notification;
use Livewire\Volt\Component;
use Wave\PaddleSubscription;
use Wave\Plan;
use Wave\Subscription;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;

middleware(['auth', \App\Http\Middleware\DenyOnAppliance::class]);
name('settings.subscription');

new class extends Component
{
    private $vtools = null;

    public $hasIncreasedInThePast = 0;

    public $pendingDiskShrinks = [];

    public function mount(): void
    {
        $vtools = new VaultTools(auth()->user());
        $vid = $vtools->getVaultId();
        $vault = $vid ? Vault::find($vid) : null;

        if (! isset($vault)) {
            $message = "Couldn't find your vault. Cannot continue.";
            notifyError($message);

            return;
        }

        $this->vid = $vault->id;

        $allowedPlans = ['admin', 'Pro', 'Team', 'Enterprise'];
        $currentSize = 0;

        // curent size returns the number of MB
        $units = pow(1024, 2);
        $currentSize = Number::FileSize(floatval($this->vtools()->currentSize() * $units), 0);

        // get disk plans
        $diskExpansionPlans = [];
        $diskPlans = Plan::where('type', 'disk')->get();
        if (count($diskPlans) > 0) {
            foreach ($diskPlans as $plan) {
                $diskExpansionPlans[] = $plan->product_id;
            }
        }

        // does this user has one or more disk expansions? if he has show the cancel disk button
        $this->hasIncreasedInThePast = 0;
        if (! auth()->user()->hasRole('admin')) {
            // find active subscriptions
            $diskExpansions = [];
            $this->pendingDiskShrinks = [];

            if (count($diskExpansionPlans) > 0) {
                // let the user choose which one to cancel...
                $diskExpansions = PaddleSubscription::where('user_id', auth()->user()->id)
                    ->where('status', 'active')
                    ->whereIn('plan_id', $diskExpansionPlans)
                    ->orderBy('created_at', 'DESC')
                    ->get();
            }

            if (count($diskExpansions) > 0) {
                $this->hasIncreasedInThePast = 1;
            }
        } else {
            // since admin does not have any plans we always show the cancel button
            $this->hasIncreasedInThePast = 1;
            $diskExpansions = ['all'];
        }

        // detect pending disk shrinks and show a warning toast...
        $pendingDiskShrinksMessage = '';
        if (count($diskExpansionPlans) > 0) {
            $this->pendingDiskShrinks = PaddleSubscription::where('user_id', auth()->user()->id)
                ->where('status', 'active')
                ->whereIn('plan_id', $diskExpansionPlans)
                ->whereNotNull('delete_at')
                ->orderBy('created_at', 'DESC')
                ->get();

            $n = count($this->pendingDiskShrinks);
            if ($n > 0) {
                $when = $this->pendingDiskShrinks[0]->delete_at;
                $pendingDiskShrinksMessage = "You have {$n} pending vault size decrease tasks scheduled for {$when}.";
                Notification::make()
                    ->title($pendingDiskShrinksMessage)
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('warning')
                    ->send();

            }
        }
    }

    public function vtools(): ?VaultTools
    {
        if (isset($this->vtools)) {
            return $this->vtools;
        }

        if (! isset($this->vid)) {
            $message = 'No vault provided. Cannot continue.';
            notifyError($message);

            return null;
        }

        $this->vtools = new VaultTools(auth()->user(), $this->vid);

        if (! isset($this->vtools)) {
            $message = "Couldn't access your vault. Cannot continue.";
            notifyError($message);

            return null;
        }

        if ($this->vtools->getVaultId() != $this->vid) {
            $message = 'Wrong vault provided. Cannot continue.';
            notifyError($message);

            return null;
        }

        if (! $this->vtools->isOpen()) {
            $message = 'Your vault is closed. Cannot continue.';
            notifyError($message);

            return null;
        }

        return $this->vtools;
    }
}
?>

<x-layouts.app>
    @volt('settings.subscription')
        <div class="relative">
            <x-app.settings-layout
                :title="__('settings.subscription_title')"
                :description="__('settings.subscription_description')"
            >
                @if(auth()->user()->hasRole('admin'))
                    <x-app.alert id="no_subscriptions" :dismissable="false" type="warning">
                        {{ __('settings.subscription_admin_notice') }}
                    </x-app.alert>
                @else
                    @subscriber

                        <div class="relative w-full h-auto">
                            <x-app.alert id="no_subscriptions" :dismissable="false" type="success">
                                <div class="flex items-center w-full">
                                    <x-phosphor-seal-check-duotone class="shrink-0 mr-1.5 -ml-1.5 w-6 h-6" />
                                    <span>{{ __('settings.subscription_active_plan', ['plan' => auth()->user()->plan()->name, 'interval' => auth()->user()->planInterval()]) }}</span>
                                </div>
                            </x-app.alert>

                            @if(count($pendingDiskShrinks) > 0)
                                <hr class="my-8 border-gray-200">

                                <span class="text-amber-600 text-xl">
                                    <i class="fas fa-triangle-exclamation fa-xl pr-2"></i>{{ $pendingDiskShrinksMessage }}
                                </span>
                            @elseif($hasIncreasedInThePast > 0)
                                <hr class="my-8 border-gray-200">

                                <div class="flex flex-col">
                                    <h5 class="mb-2 text-xl font-bold text-gray-700 dark:text-white">{{ __('settings.subscription_cancel_disk_heading') }}</h5>
                                    <p class="text-red-400">{{ __('settings.subscription_cancel_disk_description') }}</p>
                                    <p class="pt-2 text-normal">{{ __('settings.subscription_cancel_disk_note') }}</p>
                                     <button type="button" data-modal-target="selectShrinkSize-modal" data-modal-toggle="selectShrinkSize-modal" class="inline-flex self-start justify-center w-auto px-4 py-2 mt-5 text-sm font-medium text-gray-100 transition duration-150 ease-in-out border border-transparent rounded-md bg-red-800 hover:bg-red-700 focus:ring-2 focus:outline-none focus:ring-red-700 focus:shadow-outline-red-700 active:bg-red-800">{{ __('settings.subscription_cancel_disk_button') }}</button>
                                </div>
                            @endif

                            @if (session('update'))
                                <div class="my-4 text-sm text-green-600">{{ __('settings.subscription_update_success') }}</div>
                            @endif

                            <div class="mt-6">
                                <livewire:billing.update />
                            </div>
                        </div>

                        <hr class="my-8 border-gray-200">

                        <h5 class="mb-6 text-xl font-bold text-gray-700 dark:text-white">{{ __('settings.subscription_switch_plan_heading') }}</h5>
                        @include('theme::partials.plans')

                        @if(auth()->user()->hasRole(['Team', 'Enterprise', 'Self-hosted']) && auth()->user()->isTeamManager())
                            <hr class="my-8 border-gray-200 dark:border-zinc-700">
                            <h5 class="mb-2 text-xl font-bold text-gray-700 dark:text-white">{{ __('settings.seat_addon_heading') }}</h5>
                            <p class="mb-6 text-sm text-zinc-500 dark:text-zinc-300">{{ __('settings.seat_addon_description') }}</p>
                            @include('theme::partials.seatPlans')
                        @endif

                    @endsubscriber

                    @notsubscriber
                        <div class="mb-4">
                            <x-app.alert id="no_subscriptions" :dismissable="false" type="warning">
                                <div class="flex items-center space-x-1.5">
                                    <x-phosphor-shopping-bag-open-duotone class="shrink-0 mr-1.5 -ml-1.5 w-6 h-6" />
                                    <span>{{ __('settings.subscription_no_active') }}</span>
                                </div>
                            </x-app.alert>
                        </div>
                        @include('theme::partials.plans')
                        <p class="flex items-center mt-3 mb-4">
                            <x-phosphor-shield-check-duotone class="w-4 h-4 mr-1" />
                            {{ __('settings.subscription_billing_provider', ['provider' => ucfirst(config('wave.billing_provider'))]) }}
                        </p>
                    @endnotsubscriber
                @endif
            </x-app.settings-layout>
        </div>
    @endvolt
</x-layouts.app>

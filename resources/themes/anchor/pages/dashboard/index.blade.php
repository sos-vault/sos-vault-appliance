<?php

    use App\Models\SupportCase;
    use App\Models\VaultContent;
    use App\Models\Vault;

    use App\Helpers\sosVaultHelper;

    use App\Providers\VaultTools;
    use App\Providers\DataTools;

    use Livewire\Volt\Component;
    use function Laravel\Folio\{middleware, name};

    use Illuminate\Support\Facades\Log;

    middleware('auth');
    name('dashboard');

    new class extends Component
    {

        public $vid;
        public $vaultData = [];

        protected $DEBUG = false;
        protected $vtools;

        public function mount()
        {
            $vtools = new VaultTools(auth()->user());
            $vid = $vtools->getVaultId();
            $vault = $vid ? Vault::find($vid) : null;

            if(!isset($vault)) {
                $message = "Couldn't find your vault. Cannot continue.";
                notifyError($message);
                return;
            }

            $this->vid = $vault->id;

            $this->vtools = new VaultTools(auth()->user(), $this->vid);

            if(!isset($this->vtools)) {
                $message = "Couldn't access your vault. Cannot continue.";
                notifyError($message);
                return;
            }

            if($this->vtools->getVaultId() != $this->vid) {
                $message = "Wrong vault provided. Cannot continue.";
                notifyError($message);
                return;
            }

            if(!$this->vtools->isOpen()) {
                $message = "Your vault is closed. Cannot continue.";
                notifyError($message);
                return;
            }

            $dates = $this->vtools->getVaultDates();
            $usage = $this->vtools->vaultUsage();

            if (empty($usage) || ! isset($usage['Size'])) {
                notifyError('Could not read vault usage. Please try again.');
                return;
            }

            $cases = SupportCase::where('vault_id', $this->vid)
                ->where('status', 'OPEN')
                ->get();

            $this->vaultData = [
                'state'  => $this->vtools->isOpen() ? 'Open' : 'Closed',
                'shared' => false,
                'size'   => $usage['Size'],
                'usage'  => $usage['Used'],
                'isage'  => $usage['Inodes'],
                'pusage' => $usage['Use%'],
                'pisage' => $usage['IUse%'],
                'cases'  => $cases->count(),
                'pfiles' => count($this->vtools->getFiles()),
                'udirs'  => count($this->vtools->getDirs()),
                'created_at'  => $dates['creation'],
                'last_open'   => $dates['last_open'],
                'last_close' => $dates['last_close'],
            ];

        }

    }
?>

<x-layouts.app>
    @volt('dashboard')
        <x-app.container-full>
            <x-filament-actions::modals />

            @php
                $user = auth()->user();
                $isGroupMember = $user->group_id
                    && $user->group
                    && (int) $user->group->owner_id !== (int) $user->id;
                // The disk-plans modal is a SaaS upsell (paid storage tiers); on
                // the appliance vault size is managed by the admin (free, local
                // LUKS resize), so the dashboard button is SaaS-only.
                $canExpandVault = ! isAppliance()
                    && checkAccess($user, 'Vault Increase Access')
                    && (! $user->group_id || $user->isTeamManager());
            @endphp

            @if($canExpandVault)
                <div class="flex justify-end mb-4 px-2">
                    <button
                        type="button"
                        x-data
                        @click="$dispatch('open-disk-plans-modal')"
                        class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-primary-600 hover:bg-primary-500 rounded-lg transition duration-150 ease-in-out"
                    >
                        <i class="ph-duotone ph-resize text-lg"></i>
                        {{ __('settings.increase_vault_size') }}
                    </button>
                </div>

                {{-- Disk Plans Modal --}}
                <div
                    x-data="{ open: false }"
                    x-on:open-disk-plans-modal.window="open = true"
                    x-show="open"
                    x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center"
                >
                    <div class="absolute inset-0 bg-black/60" @click="open = false"></div>
                    <div class="relative z-10 w-full max-w-3xl mx-4 bg-white dark:bg-zinc-900 border rounded-xl shadow-2xl overflow-y-auto max-h-[90vh]">
                        <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-200 dark:border-zinc-700">
                            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white flex items-center gap-2">
                                <i class="ph-duotone ph-resize text-xl text-primary-600"></i>
                                {{ __('settings.increase_vault_size') }}
                            </h2>
                            <button @click="open = false" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                                <i class="ph-duotone ph-x text-xl"></i>
                            </button>
                        </div>
                        <div class="p-6">
                            @include('theme::partials.diskPlans')
                        </div>
                    </div>
                </div>
            @endif

            <x-filament-widgets::widgets
                :columns="4"
                :widgets="array_filter([
                    new \Filament\Widgets\WidgetConfiguration(
                        widget: App\Livewire\VaultBadge::class,
                        properties: [
                            'vaultData' => $this->vaultData,
                        ],
                    ),
                    new \Filament\Widgets\WidgetConfiguration(
                        widget: App\Livewire\PlanBadge::class,
                    ),
                    new \Filament\Widgets\WidgetConfiguration(
                        widget: App\Livewire\LoginSessions::class,
                    ),
                    // Billing widgets are SaaS-only — there is no subscription or
                    // invoice history on the self-hosted appliance.
                    ($isGroupMember || isAppliance()) ? null : new \Filament\Widgets\WidgetConfiguration(
                        widget: App\Livewire\Subscriptions::class,
                    ),
                    ($isGroupMember || isAppliance()) ? null : new \Filament\Widgets\WidgetConfiguration(
                        widget: App\Livewire\Invoices::class,
                    ),
                ])"
            />

        </x-app.container-full>
    @endvolt
</x-layouts.app>


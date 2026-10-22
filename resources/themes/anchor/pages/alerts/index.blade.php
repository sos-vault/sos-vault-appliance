<?php
    // Vault-level Alerts page, reached from the sidebar. Alert rules are
    // vault-wide (not tied to any one case), so unlike the per-case sosTool
    // pages this resolves "the current user's vault" the same way
    // pages/vault/index.blade.php does, rather than taking vid/did/caseid
    // from the URL.

    use App\Models\Vault;
    use App\Providers\VaultTools;

    use Livewire\Volt\Component;
    use function Laravel\Folio\{middleware, name};

    middleware('auth');
    name('alerts');

    new class extends Component
    {
        public $vid;

        public function mount()
        {
            if (isSaas() && ! checkAccess(auth()->user(), 'Alert Rules')) {
                abort(403);
            }

            $vtools = new VaultTools(auth()->user());
            $vid = $vtools->getVaultId();
            $vault = $vid ? Vault::find($vid) : null;

            if (! isset($vault)) {
                notifyError(__('vault.vault_no_vault_found'));

                return;
            }

            $this->vid = $vault->id;
        }
    }
?>

<x-layouts.app>
    @volt('alerts')
        <x-app.container>

            @if($vid)
                <x-filament::section :description="__('alerts.page_description')" :heading="__('alerts.page_title')" :contained="false"
                    icon="phosphor-bell-ringing-duotone" icon-color="primary" icon-size="lg"
                >

                    <div class="overflow-x-auto border rounded-lg dark:bg-zinc-900">
                        <livewire:alerts-table :vid="$vid" />
                    </div>

                </x-filament::section>
            @endif

        </x-app.container>
    @endvolt
</x-layouts.app>

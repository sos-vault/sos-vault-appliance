<?php

    use App\Models\Vault;
    use App\Helpers\sosVaultHelper;
    use App\Providers\VaultTools;

    use Illuminate\Support\Facades\Log;

    use Livewire\Volt\Component;
    use function Laravel\Folio\{middleware, name};

    middleware('auth');
    name('upload');

    new class extends Component
    {

        public $vid;

        public $tableTitle1  = '';
        public $description1 = '';

        public function mount()
        {
            $this->tableTitle1  = __('vault.vault_upload_page_title');
            $this->description1 = __('vault.vault_upload_page_description');

            $vtools = new VaultTools(auth()->user());
            $vid = $vtools->getVaultId();
            $vault = $vid ? Vault::find($vid) : null;

            if(!isset($vault)) {
                $message = "Couldn't find your vault. Cannot continue.";
                notifyError($message);
                return;
            }

            $this->vid = $vault->id;

            //while in development we use kewebotes vault because it has more files
            //$this->vid = 53;

        }
    }
?>

<x-layouts.app>
    @volt('upload')
        <x-app.container>
            <x-filament::section collapsible :collapsed="false" :description="$description1" :heading="$tableTitle1" :contained="false"
                icon="phosphor-cloud-arrow-up-duotone" icon-color="primary" icon-size="lg"
            >
                <div class="overflow-x-auto border rounded-lg dark:bg-zinc-900">
                    @livewire('upload', ['vid' => $vid])
                </div>
            </x-filament::section>
        </x-app.container>
    @endvolt
</x-layouts.app>


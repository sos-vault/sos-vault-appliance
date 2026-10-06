<?php
use App\Models\ApiKey;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\CodeEntry;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Livewire\Volt\Component;
use Illuminate\Contracts\Encryption\DecryptException;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;

middleware('auth');
name('settings.api');

new class extends Component implements HasActions, HasForms, Tables\Contracts\HasTable
{
    use InteractsWithActions;
    use InteractsWithForms, Tables\Concerns\InteractsWithTable;

    // variables for (b)rowing keys
    public $keys = [];

    public $isDecryptKeySet = false;

    public $isUploadKeySet = false;

    public $command = '';

    public $sosconf = '';

    public $commandDescription = '';

    public $confDescription = '';

    public ?array $data = [];

    public $instructions1 = '';

    public $instructions2 = '';

    public $instructions3 = '';

    public function mount(): void
    {
        $this->instructions1 = __('settings.api_instructions1');
        $this->instructions2 = __('settings.api_instructions2');
        $this->instructions3 = __('settings.api_instructions3');
        $this->form->fill();
        $this->refreshKeys();
        $this->updateFlags();
    }

    public function updateFlags(): void
    {
        $this->isDecryptKeySet = false;
        $this->isUploadKeySet = false;
        $decryptkey = '';
        $uploadkey = '';

        $keys = auth()->user()->apiKeys;
        foreach ($keys as $key) {
            if ($key->name == 'decrypt-pass') {
                $this->isDecryptKeySet = (isset($key) && ! empty($key));
                try {
                    $decryptkey = new Encrypter(key: getSvaultKey('svault0'), cipher: config('app.cipher'))->decrypt($key->key);
                } catch (DecryptException $e) {
                    Log::error('Exception on decrypt decrypt-pass');
                }

                if(!$decryptkey) {
                    Log::error('Could not decrypt decrypt-pass');
                }
            }

            if ($key->name == 'upload-pass') {
                $this->isUploadKeySet = (isset($key) && ! empty($key));
                try {
                    $uploadkey = new Encrypter(key: getSvaultKey('svault0'), cipher: config('app.cipher'))->decrypt($key->key);
                } catch (DecryptException $e) {
                    Log::error('Exception on decrypt upload-pass');
                }

                if(!$uploadkey) {
                    Log::error('Could not decrypt upload-pass');
                }
            }

        }

        $case = 'CAS-1234';
        $url = config('app.url').'/api/upload';
        $email = auth()->user()->email;

        $this->command = '';
        $this->sosconf = "[global]\nbatch = true\nthreads = 4\nquiet = true\nlog-size = 100\ncompression-type = xz\nclean = true\n\n[plugin_options]\nnetworking.traceroute = yes\nnetworking.ping_count = 3\n\n[report]\n";

        if ($case && $url && $email) {
            $this->command .= "sudo sos report -q --clean --batch --case-id \"{$case}\" ";
            $this->command .= ! empty($decryptkey) ? "--encrypt-pass \"{$decryptkey}\" " : '--encrypt ';

            if (! empty($decryptkey)) {
                $this->sosconf .= sprintf("encrypt-pass = %s\n", $decryptkey);
                $this->sosconf .= "\n";
            }

            if (! empty($uploadkey)) {
                $this->command .= "--upload-url \"{$url}\" --upload-user \"{$email}\" --upload-pass \"{$uploadkey}\" ";
                $this->command .= '--upload-method post';

                $this->sosconf .= sprintf("upload-url = %s\n", $url);
                $this->sosconf .= sprintf("upload-user = %s\n", $email);
                $this->sosconf .= sprintf("upload-pass = %s\n", $uploadkey);
                $this->sosconf .= sprintf("upload-method = post\n");
                $this->sosconf .= "\n";
            }
        }

        $this->commandDescription = '';
        if (! empty($decryptkey) && ! empty($uploadkey)) {
            $this->commandDescription = __('settings.api_command_description_both');
        } elseif (! empty($decryptkey) && empty($uploadkey)) {
            $this->commandDescription = __('settings.api_command_description_decrypt_only');
        } elseif (empty($decryptkey) && ! empty($uploadkey)) {
            $this->commandDescription = __('settings.api_command_description_upload_only');
        } else {
            $this->commandDescription = __('settings.api_command_description_neither');
        }

        $this->commandDescription .= '<br><br>';
        $this->commandDescription .= __('settings.api_command_description_note');

        $this->confDescription = '';
        if (! empty($decryptkey) && ! empty($uploadkey)) {
            $this->confDescription = __('settings.api_sosconf_description_both');
        } elseif (! empty($decryptkey) && empty($uploadkey)) {
            $this->confDescription = __('settings.api_sosconf_description_decrypt_only');
        } elseif (empty($decryptkey) && ! empty($uploadkey)) {
            $this->confDescription = __('settings.api_sosconf_description_upload_only');
        } else {
            $this->confDescription = __('settings.api_sosconf_description_neither');
        }

        $this->confDescription .= '<br><br>';
        $this->confDescription .= __('settings.api_sosconf_description_note');
        $this->confDescription .= '<br><br>';
        $this->confDescription .= __('settings.api_sosconf_description_reference');
        $this->confDescription .= ': <a class="text-info-500" href="/blog/sos-command/17-customizing-the-sos-report-command" target="_blank">Custmizing the sos report command</a>';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->label(__('settings.api_decrypt_key_label'))
                    ->password()
                    ->revealable(),
            ])
            ->statePath('data');
    }

    public function createUploadKey()
    {
        $randomkey = Str::random(60);

        $vaultsDisabled = (config('app.vaultsDisabled') == 'TRUE');
        $keyToStore = $vaultsDisabled
            ? $randomkey
            : (new Encrypter(key: getSvaultKey('svault0'), cipher: config('app.cipher')))->encrypt($randomkey);

        $apiKey = auth()->user()->createApiKey('upload-pass', $keyToStore);

        $uid = auth()->id() ?? 0;
        addEvent((object) ['message' => 'Upload API key added', 'name' => 'upload-pass'], 'ADD_KEY', 'SUCCESS', 'ACTIVITY', 0, 0, $uid, $uid);

        Notification::make()
            ->title(__('settings.api_create_upload_key_success'))
            ->success()
            ->send();

        $this->form->fill();

        $this->refreshKeys();
        $this->updateFlags();
    }

    public function add()
    {
        $state = $this->form->getState();

        if(!$state['key']) {
            $state['key'] = Str::random(60);
        }

        $this->validate();

        $vaultsDisabled = (config('app.vaultsDisabled') == 'TRUE');
        $keyToStore = $vaultsDisabled
            ? $state['key']
            : (new Encrypter(key: getSvaultKey('svault0'), cipher: config('app.cipher')))->encrypt($state['key']);

        $apiKey = auth()->user()->createApiKey('decrypt-pass', $keyToStore);

        $uid = auth()->id() ?? 0;
        addEvent((object) ['message' => 'Decrypt API key added', 'name' => 'decrypt-pass'], 'ADD_KEY', 'SUCCESS', 'ACTIVITY', 0, 0, $uid, $uid);

        Notification::make()
            ->title(__('settings.api_add_decrypt_key_success'))
            ->success()
            ->send();

        $this->form->fill();

        $this->refreshKeys();
        $this->updateFlags();
    }

    public function table(Table $table): Table
    {
        return $table->query(ApiKey::query()->where('user_id', auth()->user()->id))
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('created_at')->label(__('settings.api_table_created')),
                TextColumn::make('last_used_at')->label(__('settings.api_table_last_used')),
            ])
            ->emptyStateHeading(__('settings.api_table_empty_heading'))
            ->emptyStateDescription(__('settings.api_table_empty_description'))
            ->emptyStateIcon('phosphor-empty-duotone')
            ->striped(true)
            ->paginated(false)
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->modalWidth('md')
                        ->modalHeading(fn ($record): string => "View $record->name key")
                        ->modalDescription(function ($record) {
                            if ($record->name == 'decrypt-pass') {
                                return __('settings.api_view_key_modal_description_decrypt');
                            }
                            if ($record->name == 'upload-pass') {
                                return __('settings.api_view_key_modal_description_upload');
                            }
                        })
                        ->schema([
                            TextInput::make('name'),
                            TextInput::make('key')
                                ->password()
                                ->copyable(copyMessage: __('settings.api_copy_message'), copyMessageDuration: 1500)
                                ->formatStateUsing(fn ($state) => $state ?  new Encrypter(key: getSvaultKey('svault0'), cipher: config('app.cipher'))->decrypt($state) : null)
                        ]),
                    DeleteAction::make()
                        ->modalWidth('md')
                        ->modalHeading(fn ($record): string => "Delete $record->name key")
                        ->successNotification(
                            Notification::make()
                                ->success()
                                ->title(__('settings.api_delete_key_success_title'))
                                ->body(__('settings.api_delete_key_success_body')),
                        )
                        ->after(function ($record) {
                            $uid = auth()->id() ?? 0;
                            addEvent((object) ['message' => 'API key deleted', 'name' => $record->name], 'DEL_KEY', 'SUCCESS', 'ACTIVITY', 0, 0, $uid, $uid);
                            $this->updateFlags();
                        }),
                    Filament\Actions\Action::make('command')
                        ->icon('phosphor-terminal-window-duotone')
                        ->modalWidth('4xl')
                        ->modalHeading(fn ($record): string => __('settings.api_command_modal_heading'))
                        ->modalDescription(fn () => new HtmlString($this->commandDescription))
                        ->modalIcon('phosphor-terminal-window-duotone')
                        ->requiresConfirmation(false)
                        ->modalSubmitAction(false)
                        ->schema([
                            TextInput::make('command')
                                ->copyable()
                                ->readOnly()
                                ->default(fn (): string => $this->command),
                        ]),
                    Filament\Actions\Action::make('conf')
                        ->label('sos.conf')
                        ->icon('phosphor-file-ini-duotone')
                        ->modalWidth('4xl')
                        ->modalHeading(fn ($record): string => __('settings.api_sosconf_modal_heading'))
                        ->modalDescription(fn () => new HtmlString($this->confDescription))
                        ->modalIcon('phosphor-file-ini-duotone')
                        ->requiresConfirmation(false)
                        ->modalSubmitAction(false)
                        ->schema([
                            CodeEntry::make('file')
                                ->copyable()
                                ->default(fn (): string => $this->sosconf),
                        ]),
                ]),
            ]);
    }

    public function refreshKeys()
    {
        $this->keys = auth()->user()->apiKeys;
    }
}

?>

<x-layouts.app>
    @volt('settings.api')
        <div class="relative">
            <x-app.settings-layout
                :title="__('settings.api_title')"
                :description="__('settings.api_description')"
            >
                <div class="flex flex-col">

                    <div class="py-1 text-sm text-slate-600 dark:text-zinc-300">
                        {{ $instructions1 }}
                    </div>

                    <div class="py-1 text-sm text-slate-600 dark:text-zinc-300">
                        {{ $instructions2 }}
                    </div>

                    <div class="py-1 text-sm text-slate-600 dark:text-zinc-300">
                        {{ $instructions3 }}
                    </div>

                    <div x-data="{ isDecryptKeySet: @entangle('isDecryptKeySet'), isUploadKeySet: @entangle('isUploadKeySet') }">
                        <form wire:submit="add" class="w-full mt-4">
                            <div x-show="!isDecryptKeySet">
                                {{ $this->form }}
                            </div>

                            <div class="flex justify-end w-full pt-6 text-right">
                                <div x-show="!isDecryptKeySet">
                                    <x-button type="submit" class="mr-2">{{ __('settings.api_add_decrypt_key') }}</x-button>
                                </div>

                                @if(checkAccess(auth()->user(), 'Direct Upload'))
                                    <div x-show="!isUploadKeySet">
                                        <x-button wire:click="createUploadKey" >{{ __('settings.api_create_upload_key') }}</x-button>
                                    </div>
                                @endif
                            </div>
                        </form>

                        <div x-show="!isDecryptKeySet" class="text-sm p-2 pt-4 text-slate-600 dark:text-zinc-300 mb-4">
                            {{ __('settings.api_decrypt_key_hint') }}
                        </div>
                    </div>

                    <x-elements.label class="block text-sm font-medium leading-5 text-zinc-700">{{ __('settings.api_current_keys') }}</x-elements.label>
                    <div class="pt-5">
                        {{ $this->table }}
                    </div>
                </div>
            </x-app.settings-layout>
        </div>
    @endvolt
</x-layouts.app>

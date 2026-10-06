<?php

    use App\Models\VaultContent;
    use App\Models\Vault;
    use App\Models\ApiKey;
    use App\Helpers\sosVaultHelper;
    use App\Providers\VaultTools;
    use App\Providers\DataTools;

    use Filament\Notifications\Notification;

    use Filament\Tables;
    use Filament\Tables\Table;
    use Filament\Tables\Contracts\HasTable;
    use Filament\Tables\Concerns\InteractsWithTable;
    use Filament\Tables\Actions\CreateAction;
    use Filament\Tables\Columns\TextColumn;

    use Filament\Actions\Contracts\HasActions;
    use Filament\Actions\Concerns\InteractsWithActions;
    use Filament\Actions\Action;
    use Filament\Actions\ActionGroup;
    use Filament\Actions\DeleteAction;
    use Filament\Actions\BulkActionGroup;
    use Filament\Actions\DeleteBulkAction;
    use Illuminate\Database\Eloquent\Collection;

    use Filament\Forms\Concerns\InteractsWithForms;
    use Filament\Forms\Contracts\HasForms;
    use Filament\Forms\Components\TextInput;

    use Livewire\Volt\Component;
    use function Laravel\Folio\{middleware, name};
    use Illuminate\Support\Number;

    use Illuminate\Support\HtmlString;
    use Illuminate\Support\Facades\Log;

    middleware('auth');
    name('vault');

    new class extends Component implements HasForms, HasTable, HasActions
    {
        use InteractsWithActions, InteractsWithForms, InteractsWithTable;

        public ?array $data = [];

        public $vid;
        public $isCollapsed = true;

        private $vaultsDisabled = "";
        private $DEBUG = false;
        private $vtools = null;

        public $tableTitle1  = '';
        public $description1 = '';
        public $tableTitle2  = '';
        public $description2 = '';
        public $tableTitle3  = '';
        public $description3 = '';

        public function mount()
        {
            $this->tableTitle1  = __('vault.vault_upload_title');
            $this->description1 = __('vault.vault_upload_description');
            $this->tableTitle2  = __('vault.vault_packed_title');
            $this->description2 = __('vault.vault_packed_description');
            $this->tableTitle3  = __('vault.vault_unpacked_title');
            $this->description3 = __('vault.vault_unpacked_description');

            $this->vaultsDisabled = (config('app.vaultsDisabled') == "TRUE");

            $vtools = new VaultTools(auth()->user());
            $vid = $vtools->getVaultId();
            $vault = $vid ? Vault::find($vid) : null;

            if(!isset($vault)) {
                $message = __('vault.vault_no_vault_found');
                notifyError($message);
                return;
            }

            $this->vid = $vault->id;

            //while in development we use kewebotes vault because it has more files
            //$this->vid = 53;

        }

        public function vtools(): VaultTools|null
        {
            if(isset($this->vtools)) {
                return $this->vtools;
            }

            if(!isset($this->vid)) {
                $message = __('vault.dir_no_vault');
                notifyError($message);
                return null;
            }

            $this->vtools = new VaultTools(auth()->user(), $this->vid);

            if(!isset($this->vtools)) {
                $message = __('vault.dir_vault_access_error');
                notifyError($message);
                return null;
            }

            if($this->vtools->getVaultId() != $this->vid) {
                $message = __('vault.dir_wrong_vault');
                notifyError($message);
                return null;
            }

            if(!$this->vtools->isOpen()) {
                $message = __('vault.dir_vault_closed');
                notifyError($message);
                return null;
            }

            return $this->vtools;
        }

        /**
         * Initial VaultBadge stats payload. The widget itself listens for
         * refreshComponents and re-pulls via buildVaultBadgeData() so this
         * is only used at first mount.
         */
        public function vaultData(): array
        {
            return buildVaultBadgeData(auth()->user()) ?? [];
        }

        private function resolveDecryptKey(): string
        {
            foreach (auth()->user()->apiKeys as $apiKey) {
                if ($apiKey->name === 'decrypt-pass') {
                    if (! $this->vaultsDisabled) {
                        $encrypter = new \Illuminate\Encryption\Encrypter(
                            key: getSvaultKey('svault0'),
                            cipher: config('app.cipher'),
                        );

                        return $encrypter->decrypt($apiKey->key);
                    }

                    return $apiKey->key;
                }
            }

            return '';
        }

        public function canDownload(): bool
        {
            return canDownloadVaultFile();
        }

        public function downloadFile($record)
        {
            $user = auth()->user();
            $uid = $user->id;
            $gid = $user->group_id ?? $user->id;

            $vtools = $this->vtools();
            if (! $vtools) {
                return null;
            }

            $path = $vtools->getMountPoint() . "/" . $record->name;

            if (! is_file($path)) {
                notifyError(__('vault.vault_file_not_found'));
                return null;
            }

            if (! $this->canDownload()) {
                notifyError(__('vault.vault_download_failed'));
                return null;
            }

            $payload = (object)[
                'name' => $record->name,
                'path' => $record->path,
                'id'   => intval($record->id),
                'size' => $record->size,
            ];
            addEvent($payload, "DOWLOAD", "SUCCESS", "ACTIVITY", 0, $this->vid, $uid, $gid);

            return response()->download($path, $record->name);
        }

        public function delete($record) {
            //this function deletes unpacked files from the vault directly.

            $user = auth()->user();

            if (!isset($record) || empty($record)) {
                $message = __('vault.dir_no_case');
                notifyError($message);
                return;
            }

            $path  = $this->vtools()->getMountPoint() . "/";

            $uid = auth()->user()->id;
            $gid = auth()->user()->id;
            $cid = 0;

            if ($record->type !== '-') {
                $message = __('vault.vault_wrong_type');
                notifyError($message);
                return;
            }

            if(!is_file("{$path}{$record->name}")) {
                $message = __('vault.vault_file_not_found');
                notifyError($message);
                return;
            }

            unlink("{$path}{$record->name}");
            $message = __('vault.vault_file_deleted', ['name' => $record->name]);

            $payload = (object)[
                'message' => $message,
                'name'    => $record->name,
                'path'    => $record->path,
                'id'      => intval($record->id),
            ];
            addEvent($payload, "DEL_FILE", "SUCCESS", "ACTIVITY", $cid, $this->vid, $uid, $gid);

            $this->vtools()->updateContents();

            $record->delete();

            $this->DEBUG && Log::info($message);
            Notification::make()
                ->title($message)
                ->icon('phosphor-bell-ringing-duotone')
                ->iconColor('success')
                ->send();

            // Drain the toast out of the session BEFORE dispatching
            // refreshComponents so Filament's dehydrate hook doesn't fire
            // `notificationsSent` across multiple re-rendering siblings (which
            // races and renders the toast empty in production). Re-dispatch
            // each toast as a direct `notificationSent` event. Same pattern
            // as the upload flow and the Repack action.
            $pendingNotifications = session()->pull('filament.notifications', []);

            $this->dispatch('refreshComponents');

            foreach ($pendingNotifications as $notification) {
                $this->dispatch('notificationSent', notification: $notification);
            }
        }

        public function table(Table $table): Table
        {

            $unpackDescription = __('vault.vault_unpack_description');

            $deleteMessage = __('vault.vault_delete_description');

            return $table
                ->query(VaultContent::withParameters([
                        'vid' => $this->vid,
                    ])->newQuery()
                    ->where('type', '-')
                )
                ->columns([
                    TextColumn::make('name')
                        ->label(__('vault.vault_filename_col'))
                        ->searchable()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('size')
                        ->label(__('vault.vault_col_size'))
                        ->formatStateUsing(fn ($record) => Number::fileSize($record->size))
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('date')
                        ->label(__('vault.vault_col_date'))
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('time')
                        ->label(__('vault.vault_col_time'))
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                ])
                ->defaultSort('name', 'desc')
                ->emptyStateHeading(__('vault.no_data'))
                ->emptyStateDescription(__('vault.vault_packed_empty'))
                ->emptyStateIcon('phosphor-empty-duotone')
                ->striped(true)
                ->deferColumnManager(false)
                ->persistSearchInSession()
                ->persistColumnSearchesInSession()
                ->paginated(true)
                ->recordActions([
                    ActionGroup::make([
                        Action::make('checksum')
                            ->label(__('vault.vault_checksum_label'))
                            ->icon('phosphor-shield-check-duotone')
                            ->modalWidth('2xl')
                            ->modalSubmitActionLabel(__('vault.vault_checksum_submit'))
                            ->modalCancelAction(false)
                            ->modalIcon('phosphor-shield-check')
                            ->schema([
                                TextInput::make('sum')
                                    ->label("SHA256")
                                    ->default(fn ($record) => $record->sum)
                                    ->extraAttributes(['class' => 'bg-transparent'])
                                    ->readonly()
                                    ->disabled()
                            ]),
                        Action::make('download')
                            ->label(__('vault.vault_download_label'))
                            ->icon('phosphor-download-simple-duotone')
                            ->tooltip(__('vault.vault_download_tooltip'))
                            ->visible(fn () => $this->canDownload())
                            ->action(fn ($record) => $this->downloadFile($record)),
                        Action::make('unpack')
                            ->label(__('vault.vault_unpack_label'))
                            ->icon('phosphor-lock-open-duotone')
                            ->modalWidth('2xl')
                            ->modalSubmitActionLabel(__('vault.vault_unpack_modal_submit'))
                            ->modalHeading(__('vault.vault_unpack_modal_heading'))
                            ->modalDescription(__('vault.vault_unpack_description'))
                            ->modalIcon('phosphor-lock-open')
                            ->modalHidden(fn (VaultContent $record) => ! str_ends_with($record->name, '.gpg'))
                            ->action(function (array $data, VaultContent $record): void {
                                $this->dispatch('start-progress', fid: $record['id'], key: $data['passphrase'] ?? '');
                            })
                            ->schema(fn (VaultContent $record): array => str_ends_with($record->name, '.gpg') ? [
                                TextInput::make('passphrase')
                                    ->label(__('vault.vault_passphrase_label'))
                                    ->password()
                                    ->revealable()
                                    ->required()
                                    ->default(fn () => $this->resolveDecryptKey()),
                            ] : []),
                        DeleteAction::make()
                            ->requiresConfirmation()
                            ->modalHeading(fn ($record): string => __('vault.vault_delete_heading', ['name' => $record->name]))
                            ->modalDescription($deleteMessage)
                            ->action(function ($record): void {
                                $this->delete($record);
                            })
                        ->mutateDataUsing(function (array $data): array {
                            $data['user_id'] = auth()->id();
                            return $data;
                        }),
                    ])
                ])
                ->toolbarActions([
                    DeleteBulkAction::make()
                        ->requiresConfirmation()
                        ->modalDescription($deleteMessage)
                        //->authorizeIndividualRecords('delete')
                        ->action(function (Collection $records): void {
                            foreach($records as $record) {
                                $this->delete($record);
                            }
                        })
                ]);
        }

    }
?>

<x-layouts.app>
    @volt('vault')
        <x-app.container>
            @script
            <script>
                $wire.on('refreshComponents', () => {
                    $wire.$refresh();
                });
            </script>
            @endscript

            <x-filament-actions::modals />

            <x-filament-widgets::widgets
                :columns="4"
                :widgets="[
                    new \Filament\Widgets\WidgetConfiguration(
                        widget: App\Livewire\VaultBadge::class,
                        properties: [
                            'vaultData' => $this->vaultData(),
                        ],
                    ),
                ]"
            />

            <div class="mb-5"></div>

            <x-filament::section collapsible :collapsed="$isCollapsed" :description="$description1" :heading="$tableTitle1" :contained="false"
                icon="phosphor-cloud-arrow-up-duotone" icon-color="primary" icon-size="lg"
            >

                <div class="overflow-x-auto border rounded-lg dark:bg-zinc-900">
                    @livewire('upload', ['vid' => $vid, 'withProgressBar' => "false"])
                </div>

            </x-filament::section>

            <div class="mb-5"></div>

            <x-filament::section collapsible :description="$description2" :heading="$tableTitle2" :contained="false"
                icon="phosphor-folders-duotone" icon-color="primary" icon-size="lg"
            >

                <div class="overflow-x-auto border rounded-lg">
                    {{ $this->table}}
                </div>

            </x-filament::section>

            <div class="mb-5"></div>

            <x-filament::section collapsible :description="$description3" :heading="$tableTitle3" :contained="false"
                icon="phosphor-folders-duotone" icon-color="primary" icon-size="lg"
            >

                <div class="overflow-x-auto border rounded-lg">
                    @livewire('list-directories', ['vid' => $vid])
                </div>

            </x-filament::section>

            @livewire('progress-bar', ['vid' => $vid])

        </x-app.container>
    @endvolt
</x-layouts.app>


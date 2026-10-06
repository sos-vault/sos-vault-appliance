<?php

    use App\Events\FixSosHtmlRequested;
    use App\Models\ContentsRequest;
    use App\Models\SupportCase;
    use App\Models\User;
    use App\Models\Vault;
    use App\Providers\VaultTools;
    use App\Services\Fleet\FleetIdentityBackfiller;

    use App\Helpers\sosVaultHelper;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Log;

    use App\Notifications\SharedVaultAccessedNotification;
    use Filament\Notifications\Notification;

    use Filament\Actions\Concerns\InteractsWithActions;
    use Filament\Actions\Contracts\HasActions;
    use Filament\Forms\Concerns\InteractsWithForms;
    use Filament\Forms\Contracts\HasForms;
    use Filament\Support\Enums\TextSize;
    use Filament\Tables\Columns\IconColumn;
    use Filament\Tables\Columns\TextColumn;
    use Filament\Tables\Concerns\InteractsWithTable;
    use Filament\Tables\Contracts\HasTable;
    use Filament\Tables\Filters\SelectFilter;
    use Filament\Tables\Table;
    use Illuminate\Support\HtmlString;

    use Livewire\Volt\Component;
    use Livewire\Attributes\On;
    use function Livewire\Volt\{mount, state, computed};

    use function Laravel\Folio\{middleware, name};

    use Filament\Schemas\Components\Icon;

    middleware('auth');
    name('sosbrowser');
    state(['caseid' => null]);

    new class extends Component implements HasActions, HasForms, HasTable
    {
        use InteractsWithActions;
        use InteractsWithForms, InteractsWithTable;

        public ?array $data = [];

        public $caseid;
        public $vid;
        public $did;
        public $tree;
        public $mode;

        public int $sme = 0;
        public ?int $ownerId = null;

        public $color = "primary";

        public $statusLine = '';
        public $errorState = false;
        public $loadingState = false;

        private $vtools;

        public function mount(?string $caseid = null)
        {
            $this->statusLine = __('vault.browser_status_select_table');
            $this->sme = (int) request()->query('sme', 0);

            if ($this->sme > 0) {
                // Shared mode: vid/did come from query params, not from SupportCase owned by current user.
                $this->vid = request()->query('vid');
                $this->did = request()->query('did');

                // Resolve the vault owner so vtools() can operate on their behalf.
                $vault = Vault::where('id', $this->vid)->first();
                if ($vault) {
                    $this->ownerId = (int) $vault->owner;
                }

                // Notify the owner that someone else opened their shared report.
                if (isset($this->ownerId) && auth()->id() !== $this->ownerId) {
                    $this->notifyOwnerOfAccess();
                }

                if (! isset($this->caseid) || empty($this->caseid) || $this->caseid == 'null') {
                    $this->caseid = 0;
                } else {
                    $this->caseSeletcedSequence($this->caseid);
                }

                return;
            }

            if(!isset($this->caseid) || empty($this->caseid) || $this->caseid == '' || $this->caseid == 'null') {
                $message = __('vault.browser_no_case_select');
                Notification::make()
                    ->title($message)
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('warning')
                    ->send();

                $this->caseid = 0;
            } else {

                $case = SupportCase::where('id', $this->caseid)->first();
                if(isset($case)) {
                    $this->did = $case->file_id;
                    $this->vid = $case->vault_id;
                }

                if(!isset($this->vid) || !isset($this->did)) {
                    $message = __('vault.browser_not_enough_params');
                    notifyError($message);
                    $this->errorState = true;
                    return;
                }

                $this->caseSeletcedSequence($this->caseid);
            }
        }

        #[On('livewire:case-chosen')]
        public function handleSelection($cid)
        {
            if(!isset($cid) || empty($cid) || $cid == '' || $cid == 'null') {
                $message = __('vault.browser_no_case');
                notifyError($message);
                $this->errorState = true;
                return null;
            }

            $case = SupportCase::where('id', $cid)->first();
            if(!isset($case)) {
                $message = __('vault.browser_case_not_found');
                notifyError($message);
                $this->errorState = true;
                return;
            }

            $this->did = $case->file_id;
            $this->vid = $case->vault_id;

            if(!isset($this->vid) || !isset($this->did)) {
                $message = __('vault.browser_not_enough_params');
                notifyError($message);
                $this->errorState = true;
                return;
            }

            // If this is a public case from a different vault, switch to shared
            // mode so vtools() operates as the vault owner, not the current user.
            // Never apply shared mode to the vault owner — they retain full control.
            $gid = auth()->user()->group_id ?? auth()->user()->id;
            $vault = Vault::where('id', $this->vid)->first();
            $isVaultOwner = $vault && (int) $vault->owner === auth()->id();
            if ($case->is_public && $case->group != $gid && ! $isVaultOwner) {
                if ($vault) {
                    $this->ownerId = (int) $vault->owner;
                    $this->sme = 1;
                }
            }

            // Reset cached vtools — vid changed so the cached instance is stale.
            $this->vtools = null;

            $this->caseid = $cid;

            $vt = $this->vtools();
            if (! $vt) {
                return;
            }

            $mountDir = $vt->getMountPoint();

            $dir = $vt->getDirById($this->did);

            if(!isset($dir)) {
                // if the user is here is because the case->file_id (did) no longer matches the id inside .contemts.json
                // (maybe files where moved from one vault to another). Try using the directory name and fix this...

                $dir = $vt->getDirByName(basename($case->path));

                if(!isset($dir)) {
                    $message = __('vault.dir_not_found');
                    notifyError($message);
                    $this->errorState = true;
                    return;
                }

                $case->file_id = $dir->id;
                $case->save();
            }

            $tree = $vt->getContents("{$mountDir}/{$dir->name}");

            if(!isset($tree)) {
                $message = "Couldn't find the directory. Cannot continue";
                notifyError($message);
                $this->errorState = true;
                return;
            }

            $this->tree = base64_encode(json_encode($tree));

            // Self-heal pre-fleet cases: persist machine_id/hostname while the
            // vault is open (cheap .hostData.json read; no-op once populated).
            if (empty($case->machine_id) || empty($case->hostname)) {
                app(FleetIdentityBackfiller::class)->ensure($vt, $this->vid, $this->did, "{$mountDir}/{$dir->name}", $case);
            }

            // Retro-fix an older report whose sos_reports/sos.html predates the link
            // rewrite. Queued (never blocks case open) and idempotent — a no-op once
            // the file carries the fixed marker.
            $vaultOwnerId = Vault::where('id', $this->vid)->value('owner') ?? auth()->id();
            FixSosHtmlRequested::dispatch($vaultOwnerId, $this->vid, $this->did, $cid);

            $this->dispatch('openSosReport',
                cid:  $cid,
                vid:  $this->vid,
                did:  $this->did,
                root: 'main',
                mode: 'full',
                tree:  $this->tree,
                cid2:  '',
            )->to('directory-tree');

            $message = __('vault.browser_case_loaded', ['case' => $case->case]);
            Notification::make()
                ->title($message)
                ->icon('phosphor-bell-ringing-duotone')
                ->iconColor('success')
                ->send();

            return;
        }

        #[On('case-selection-sequece')]
        public function caseSeletcedSequence($cid)
        {
            $this->tree = '';
            $this->loadingState = true;
            $this->errorState = false;
            $this->statusLine = __('vault.browser_loading');
            $this->dispatch('clear-diff', cid: $cid);
            $this->dispatch('sidebar-toggled');
        }

        #[On('case-selection-done')]
        public function toggleLoading()
        {
            $this->loadingState = false;
            $this->errorState = false;
            $this->statusLine = "";
            $this->dispatch('switch-tool-controls-case',
                cid:  $this->caseid,
                vid:  $this->vid,
                did:  $this->did,
            );

            // Hand the open case to Mil (the AI chat widget) so questions about
            // "this system" get the live sosreport data injected. The dispatch
            // updates the widget live in THIS window; the session write lets every
            // other window/page (tool popups, later navigations) that mounts its
            // own widget adopt the same case — otherwise Mil is blind everywhere
            // except the Browse SOS Report tab.
            if (! empty($this->did) && ! empty($this->caseid)) {
                session(['mil_open_case' => [
                    'did' => (int) $this->did,
                    'cid' => (int) $this->caseid,
                ]]);

                $this->dispatch('chat-set-case',
                    did: (int) $this->did,
                    cid: (int) $this->caseid,
                );
            }
        }

        public function vtools(): VaultTools|null
        {
            if(isset($this->vtools)) {
                return $this->vtools;
            }

            if(!isset($this->vid)) {
                $message = __('vault.dir_no_vault');
                notifyError($message);
                $this->errorState = true;
                return null;
            }

            // In shared mode use the vault owner's account so we can operate
            // on their vault even when they are logged out.
            $vtoolsUser = ($this->sme > 0 && isset($this->ownerId))
                ? User::find($this->ownerId)
                : auth()->user();

            $this->vtools = new VaultTools($vtoolsUser, $this->vid);

            if(!isset($this->vtools)) {
                $message = __('vault.browser_vault_no_access');
                notifyError($message);
                $this->errorState = true;
                return null;
            }

            if($this->vtools->getVaultId() != $this->vid) {
                $message = __('vault.dir_wrong_vault');
                notifyError($message);
                $this->errorState = true;
                return null;
            }

            if(!$this->vtools->isOpen()) {
                if ($this->sme > 0) {
                    // Owner logged out and their vault was closed. Re-open it.
                    if (! $this->vtools->OpenVault()) {
                        $message = __('vault.browser_shared_vault_closed');
                        notifyError($message);
                        $this->errorState = true;
                        return null;
                    }
                } else {
                    $message = __('vault.dir_vault_closed');
                    notifyError($message);
                    $this->errorState = true;
                    return null;
                }
            }

            return $this->vtools;
        }

        protected function notifyOwnerOfAccess(): void
        {
            if (! isset($this->ownerId)) {
                return;
            }

            $owner  = User::find($this->ownerId);
            $viewer = auth()->user();

            if (! $owner || ! $viewer) {
                return;
            }

            $link = $this->caseid ? url("/sosbrowser/{$this->caseid}") : url('/dashboard');

            $owner->notify(new SharedVaultAccessedNotification($viewer, $link));
        }

        public function setErrorState()
        {
            $this->errorState = true;
        }

        public function table(Table $table): Table
        {
            $gid = auth()->user()->group_id ?? auth()->id();
            $uid = auth()->id();

            return $table
                ->query(SupportCase::query()->where(function ($q) use ($gid) {
                    $q->where('group', $gid)->orWhere('is_public', true);
                })->whereNotIn('id', function ($q) use ($uid) {
                    $q->select('case_id')->from('user_hidden_cases')->where('user_id', $uid);
                })->with('createdBy'))
                ->recordUrl(function (SupportCase $record) use ($gid): string {
                    if ($record->is_public && (int) $record->group !== (int) $gid) {
                        $creq = ContentsRequest::where('vault_id', $record->vault_id)
                            ->where('dir_id', $record->file_id)
                            ->where('file_id', 0)
                            ->first();
                        if ($creq?->url) {
                            return $creq->url;
                        }
                    }

                    return url("/sosbrowser/{$record->id}");
                })
                ->columns([
                    TextColumn::make('case')
                        ->label(__('cases.col_case'))
                        ->searchable()
                        ->color('primary')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('serial')
                        ->label(__('cases.col_serial'))
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('customer')
                        ->label(__('cases.col_customer'))
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('host')
                        ->label(__('cases.col_host'))
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('label')
                        ->label(__('cases.col_label'))
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('version')
                        ->label(__('cases.col_version'))
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('status')
                        ->label(__('cases.col_status'))
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'OPEN' => 'primary',
                            'WAITCUST' => 'info',
                            'CLOSED' => 'danger',
                            'REOPEN' => 'primary',
                            'BLOCKED' => 'warning',
                            'SOLVED' => 'danger',
                            'DONE' => 'gray',
                            'WAIT' => 'info',
                            default => 'gray',
                        })
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('link')
                        ->label(__('cases.col_link'))
                        ->formatStateUsing(fn (string $state): HtmlString => new HtmlString("<a href='{$state}' target='_blanc'>".__('cases.goto_ticket').'</a>'))
                        ->color('primary')
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('date')
                        ->label(__('cases.col_date'))
                        ->dateTime('d/M/Y')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('os_version')
                        ->icon(fn ($record): string => $record->os_icon)
                        ->label(__('cases.col_os'))
                        ->tooltip(fn ($state): string => $state)
                        ->formatStateUsing(function ($state): string {
                            $temp = explode(' ', $state);
                            $small = array_slice($temp, 0, 2);

                            return implode(' ', $small).' ...';
                        })
                        ->size(TextSize::Small)
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('sos_version')
                        ->label(__('cases.col_sos_version'))
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('createdBy.name')
                        ->label(__('cases.col_owner'))
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    IconColumn::make('is_public')
                        ->label(__('cases.col_public'))
                        ->boolean()
                        ->trueIcon('phosphor-globe-duotone')
                        ->falseIcon('')
                        ->trueColor('info')
                        ->tooltip(fn ($state) => $state ? __('cases.col_public_tooltip') : null)
                        ->toggleable(isToggledHiddenByDefault: false),
                ])
                ->defaultSort('serial', 'asc')
                ->emptyStateHeading(__('cases.empty_heading'))
                ->emptyStateDescription(__('cases.empty_description'))
                ->emptyStateIcon('phosphor-empty-duotone')
                ->striped(true)
                ->deferColumnManager(false)
                ->reorderableColumns()
                ->persistSearchInSession()
                ->persistColumnSearchesInSession()
                ->paginated(true)
                ->filters([
                    SelectFilter::make('status')
                        ->options([
                            'OPEN' => __('cases.status_open'),
                            'REOPEN' => __('cases.status_reopen'),
                            'CLOSED' => __('cases.status_closed'),
                            'BLOCKED' => __('cases.status_blocked'),
                            'SOLVED' => __('cases.status_solved'),
                            'DONE' => __('cases.status_done'),
                            'WAITCUST' => __('cases.status_waitcust'),
                            'WAIT' => __('cases.status_wait'),
                        ]),
                ]);
        }

    }
?>

<x-layouts.app>
    @volt('sosbrowser')
        <x-app.container-full>
            @if(isset($caseid))
                <x-filament-actions::modals />

                @script
                <script>
                    document.title = "SOS Browser";
                    window.sosViewer.addTab(document.title);
                    window.sosViewer.sme = {{ $sme }};
                    $wire.on('refresh-this', window.sosViewer.filelistRefresh);
                    window.addEventListener('load', window.sosViewer.checkIfPopupsAllowed);
                    window.addEventListener('sidebar-toggled', window.sosViewer.fixToolControlsSize);
                    window.addEventListener('livewire:update', window.sosViewer.fixToolControlsSize);
                    window.addEventListener('clear-diff', (event) => {
                        document.getElementById('main').innerHTML='';
                        const data = { 'cid': event.detail.cid, };
                        window.dispatchEvent(new CustomEvent('livewire:case-chosen', {detail: data}));
                    });
                </script>
                @endscript

                @livewire('directory-tree', [
                    'cid'    => $this->caseid,
                    'vid'    => $this->vid,
                    'did'    => $this->did,
                    'root'   => 'main',
                    'mode'   => 'full',
                    'tree'   => $this->tree,
                    'cid2'    => '',
                ])

                @livewire('tool-controls', [
                    'caseid' => $caseid,
                    'parent' => 'sosBrowser',
                    'color' => $color,
                    'sme'   => $sme,
                ])

                <main wire:ignore id="root" class="mt-[15.0rem] pb-2 border-1 dark:bg-zinc-900 border-gray-200 rounded-lg h-full overflow-none text-sm text-gray-800 dark:text-gray-100 tree ">

                    <div id="loading" class="flex justify-center p-4 w-full">

                       <div id="statusBlock" x-show="statusLine || errorState"
                            x-data="{
                                errorState:   @entangle('errorState'),
                                loadingState: @entangle('loadingState'),
                                statusLine :  @entangle('statusLine'),

                            }"
                            class="flex justify-center w-full">

                                <div x-show="statusLine && !errorState" class="flex justify-center items-center w-auto p-4 text-zinc-600 dark:text-zinc-300 my-4 text-sm">

                                    <div x-show="loadingState" x-cloak >
                                        <x-filament::loading-indicator class="h-8 w-8" />
                                    </div>

                                    <span x-text="statusLine"></span>
                                </div>

                                <div x-show="errorState" x-cloak class="flex text-warning-500 mt-4 text-sm">
                                    <x-phosphor-warning-duotone class="w-6 h-6 mr-2" /> {{ __('vault.browser_dir_not_found') }}
                                </div>
                        </div>

                    </div>

                    @if (! $caseid && $sme === 0)
                        <div id="caseSelectionTable" wire:ignore.self class="px-4 pb-4">
                            <div class="overflow-x-auto border rounded-lg">
                                {{ $this->table }}
                            </div>
                        </div>
                    @endif

                    <div id="main"></div>

                </main>
            @endif

            @if(!empty($fileData))
                @livewire('file-list-context-menu', ['fileData' => $fileData])
            @endif

            <div wire:click="$refresh" id="fileListRefresh" class="hidden"></div>
        </x-app.container>
    @endvolt
</x-layouts.app>

<?php
    use App\Models\SupportCase;
    use App\Models\Tools;
    use App\Models\Bookmark;
    use App\Models\FileList;
    use App\Models\ContentsRequest;
    use App\Models\Annotation;
    use App\Models\User;
    use App\Helpers\sosVaultHelper;
    use Carbon\Carbon;

    use Filament\Infolists\Components\TextEntry;
    use Filament\Schemas\Concerns\InteractsWithSchemas;
    use Filament\Schemas\Contracts\HasSchemas;
    use Filament\Schemas\Schema;
    use Filament\Schemas\Components\Grid;
    use Filament\Schemas\Components\Fieldset;
    use Filament\Support\Enums\TextSize;
    use Filament\Schemas\Components\Section;
    use Filament\Actions\Action;
    use Filament\Actions\Contracts\HasActions;
    use Filament\Actions\Concerns\InteractsWithActions;
    use Filament\Support\Enums\Size;
    use Filament\Schemas\Components\Flex;
    use Filament\Forms\Components\TextInput;
    use Filament\Infolists\Components\ViewEntry;
    use Filament\Actions\ActionGroup;
    use Filament\Support\Enums\Width;
    use Filament\Forms\Components\Select;

    use Illuminate\Validation\ValidationException;

    use Filament\Schemas\Components\Tabs;
    use Filament\Schemas\Components\Tabs\Tab;
    use Filament\Support\Enums\IconSize;
    use Filament\Notifications\Notification;
    use Filament\Schemas\Components\Icon;

    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Str;
    use Livewire\Volt\Component;
    use Livewire\Attributes\Computed;
    use Livewire\Attributes\On;
    use function Livewire\Volt\{mount, computed};

    use function Laravel\Folio\{middleware, name};

    middleware('auth');
    name('tool-controls');

    new class extends Component implements HasSchemas, HasActions
    {
        use InteractsWithSchemas, InteractsWithActions;

        public ?array $data = [];
        public $caseid;
        public $vid;
        public $did;

        public $parent;
        public $color;
        public $title;
        public $icon;

        public $caseid2;
        public ?int $caseSwitcher = null;

        public int $sme = 0;
        public bool $isDirShared = false;
        public string $dirShareUrl = '';
        public bool $isCasePublic = false;

        #[Computed]
        public function caseOptions(): array
        {
            $gid = auth()->user()->group_id ?? auth()->user()->id;
            $cases = SupportCase::where(function ($q) use ($gid) {
                    $q->where('group', $gid)->orWhere('is_public', true);
                })
                ->where('id', '!=', $this->caseid)
                ->orderBy('created_at')
                ->get();

            $options = [];
            foreach ($cases as $case) {
                $color = match ($case->status) {
                    'OPEN'     => 'primary',
                    'WAITCUST' => 'info',
                    'CLOSED'   => 'danger',
                    'REOPEN'   => 'primary',
                    'BLOCKED'  => 'warning',
                    'SOLVED'   => 'danger',
                    'DONE'     => 'gray',
                    'WAIT'     => 'info',
                    default    => 'gray',
                };
                $entry = '';
                if ($case->is_public && $case->vault_id !== $this->vid) {
                    $entry .= '<span class="text-xs text-info-400 font-semibold mr-1">[' . __('vault.tool_share_public_label') . ']</span>';
                }
                //$entry .= sprintf('<span class="text-%s-400">', $color);
                $entry .= sprintf('<span class="text-zinc-600 dark:text-zinc-100">', $color);
                $entry .= sprintf('<x-%s class="w-6 h-6 mr-2" />', str_replace('simpleicon', 'si', $case->os_icon));
                $entry .= __('vault.case_label', ['case' => $case->case]);
                $entry .= '&horbar;';
                $entry .= __('vault.case_serial', ['serial' => $case->serial]);
                $entry .= '</span>';

                $options[$case->id] = $entry;
            }

            return $options;
        }

        // file lists variables
        public $fileData;
        public $filelistModalIsOpen = false;

        public $filelistName;
        public $bookmarks2del;

        public function mount($caseid, $parent, $color, int $sme = 0) {

            if(!isset($caseid) || !isset($parent)) {
                Notification::make()
                    ->title(__('vault.tool_missing_params'))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                    return false;
            }

            $this->caseid = $caseid;
            $this->parent = $parent;
            $this->sme    = $sme;

            if(!isset($color)) {
                $this->color  = 'primary';
            } else {
                $this->color  = $color;
            }

            $tool = Tools::query()->where('name', $parent)
                ->where('enabled', true)
                ->first();

            if(!isset($tool)) {
                Notification::make()
                    ->title(__('vault.tool_not_found'))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                    return false;
            }

            $this->title = $tool->title;
            $this->icon = $tool->icon;

            $case = SupportCase::where('id', $this->caseid)->first();
            if(isset($case)) {
                $this->did = $case->file_id;
                $this->vid = $case->vault_id;
                $this->isCasePublic = (bool) ($case->is_public ?? false);
            }

            $this->loadDirShareState();

            if ($this->parent === 'sosBrowser' && isset($this->vid) && isset($this->did)) {
                $url = "/sosTool/{$this->vid}/{$this->did}/Summary/{$this->caseid}";
                $this->dispatch('checkTab', ['url' => $url, 'tabName' => 'SOS Summary']);
            }
        }

        protected function loadDirShareState(): void
        {
            if (! isset($this->vid) || ! isset($this->did)) {
                return;
            }

            $creq = ContentsRequest::where('vault_id', $this->vid)
                ->where('dir_id', $this->did)
                ->where('file_id', 0)
                ->first();

            $this->isDirShared  = $creq && in_array($creq->status, ['SHARED', 'LOCKED']);
            $this->dirShareUrl  = $creq?->url ?? '';
        }

        protected function ensureDirContentsRequest(): ContentsRequest
        {
            $uid = auth()->user()->id;
            $gid = auth()->user()->group_id ?? auth()->user()->id;
            $vid = $this->vid;
            $did = $this->did;
            $cid = $this->caseid;

            $creq = ContentsRequest::where('vault_id', $vid)
                ->where('dir_id', $did)
                ->where('file_id', 0)
                ->first();

            if (! $creq) {
                $hash = Str::random(40);
                $url  = url("sosSharedDir/{$hash}");

                $creq = ContentsRequest::create([
                    'vault_id' => $vid,
                    'dir_id'   => $did,
                    'file_id'  => 0,
                    'case_id'  => $cid,
                    'status'   => 'VALID',
                    'comments' => '',
                    'url'      => $url,
                    'owner'    => $uid,
                    'group'    => $gid,
                    'perms'    => '750',
                    'expire'   => Carbon::now()->addDays(7)->format('Y-m-d H:i:s'),
                ]);
            }

            if (! Annotation::where('vault_id', $vid)->where('dir_id', $did)->where('file_id', 0)->exists()) {
                Annotation::create([
                    'vault_id' => $vid,
                    'dir_id'   => $did,
                    'file_id'  => 0,
                    'owner'    => $uid,
                    'group'    => $gid,
                    'perms'    => '750',
                    'status'   => 'PRIVATE',
                ]);
            }

            $this->dirShareUrl = $creq->url;

            return $creq;
        }

        public function getDirModalContent(): string
        {
            if (! \App\Services\VaultAccess::canManage(auth()->user(), $this->vid)) {
                return '';
            }

            if (empty($this->dirShareUrl)) {
                $this->ensureDirContentsRequest();
            }

            return $this->dirShareUrl;
        }

        public function shareDir(bool $isPublic = false): void
        {
            if (! \App\Services\VaultAccess::canManage(auth()->user(), $this->vid)) {
                return;
            }

            $creq = $this->ensureDirContentsRequest();
            $creq->status = 'SHARED';

            if ($isPublic) {
                $creq->expire = Carbon::now()->addYears(100)->format('Y-m-d H:i:s');
            }

            $creq->save();

            $annot = Annotation::where('vault_id', $this->vid)
                ->where('dir_id', $this->did)
                ->where('file_id', 0)
                ->first();

            if ($annot) {
                $annot->status = 'SHARED';
                $annot->save();
            }

            if ($isPublic) {
                SupportCase::where('id', $this->caseid)->update(['is_public' => true]);
                $this->isCasePublic = true;
            }

            $this->isDirShared = true;
            $this->dispatch('copy-dir-to-clipboard', ['url' => $this->dirShareUrl]);
        }

        public function unshareDir(): void
        {
            if (! \App\Services\VaultAccess::canManage(auth()->user(), $this->vid)) {
                return;
            }

            $creq = ContentsRequest::where('vault_id', $this->vid)
                ->where('dir_id', $this->did)
                ->where('file_id', 0)
                ->first();

            if ($creq) {
                $creq->status = 'PRIVATE';
                $creq->save();
            }

            $annot = Annotation::where('vault_id', $this->vid)
                ->where('dir_id', $this->did)
                ->where('file_id', 0)
                ->first();

            if ($annot) {
                $annot->status = 'PRIVATE';
                $annot->save();
            }

            SupportCase::where('id', $this->caseid)->update(['is_public' => false]);
            $this->isCasePublic = false;
            $this->isDirShared = false;
        }

        public function copyDirUrl(): void
        {
            $this->dispatch('copy-dir-to-clipboard', ['url' => $this->dirShareUrl]);
        }

        #[On('switch-tool-controls-case')]
        public function switchCase($vid, $did, $cid)
        {
            $this->vid = $vid;
            $this->did = $did;
            $this->caseid = $cid;
            $this->loadDirShareState();
            $newCase = SupportCase::find($cid);
            $this->isCasePublic = (bool) ($newCase?->is_public ?? false);
            $this->dispatch('reset-breadcrumbs');
            $this->dispatch('reset-searchbox');
            $this->dispatch('sidebar-toggled');
        }

        #[On('livewire:add-filelist')]
        public function openAddFilelistMenu($name, $fullpath, $filetype, $icon)
        {
            // when the user click the "add to File List" icon this function gets executed
            // when fileData is populated "injects" the file-list-context-menu component at the bottom of this view
            // with fileData as its only parameter. The component includes a modal but has not been opened yet.
            $this->fileData = [
                'vid'      => $this->vid,
                'cid'      => $this->caseid,
                'did'      => $this->did,
                'name'     => $name,
                'fullpath' => $fullpath,
                'filetype' => $filetype,
                'icon'     => $icon,
            ];
        }

        #[On('trigger-open-add-filelist-modal')]
        public function openAddFileistModal()
        {
            // event emited by the child's component (file-list-context-menu) mount function.
            if(!$this->filelistModalIsOpen) {
                // This is the actual open of the modal.
                $this->dispatch('open-modal', id: 'fileListContextMenu');
            }
        }

        #[On('livewire:set-filelist-modal-open')]
        public function setAddFileistModalOpen()
        {
            // just to let know this parent component that a child component modal is open.
            $this->filelistModalIsOpen = true;
        }

        #[On('livewire:unset-filelist-modal-open')]
        public function unsetAddFileistModalOpen()
        {
            // let know this parent component that a child component modal has been closed.
            $this->fileData = [];
            $this->filelistModalIsOpen = false;
        }

        #[On('del-filelist')]
        public function delFileList($id)
        {
            $filelist = FileList::where('id', $id)
                ->where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->first();

            if(!isset($filelist)) {
                return;
            }

            // The toolbar shows one button per logical (same-named) FileList, so
            // deleting it removes every per-case copy in this vault along with
            // all of their bookmarks.
            $sameNamedIds = FileList::where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->where('name', $filelist->name)
                ->pluck('id');

            Bookmark::where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->whereIn('filelist_id', $sameNamedIds)
                ->delete();

            FileList::whereIn('id', $sameNamedIds)->delete();
        }

        public function renameFileList($id, $name)
        {
            $existing = FileList::where('name', $name)
                ->where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->first();

            if(isset($existing) || !empty($existing)) {
                $this->filelistName = "";
                throw ValidationException::withMessages([
                    'filelistName' => ['A file list with that same name already exists.'],
                ]);
            }

            $filelist = FileList::where('id', $id)
                ->where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->first();

            if(!isset($filelist) || empty($filelist)) {
                Notification::make()
                    ->title(__('vault.tool_filelist_not_found'))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                return;
            }

            $oldname = $filelist->name;

            if($oldname == $name) {
                $this->filelistName = "";
                throw ValidationException::withMessages([
                    'filelistName' => ['The new name must be different from the current one.'],
                ]);
            }

            // Rename every same-named list in this vault (one per case) so the
            // single logical FileList keeps collapsing to one button.
            FileList::where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->where('name', $oldname)
                ->update(['name' => $name, 'title' => $name]);

            Notification::make()
                ->title(__('vault.tool_filelist_name_changed', ['name' => $name]))
                ->icon('phosphor-bell-ringing-duotone')
                ->iconColor('primary')
                ->send();

            // refresh component to reflect name change.
            $this->dispatch('refresh-this');
        }

        public function editFileList($id, $files)
        {
            $filelist = FileList::where('id', $id)
                ->where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->first();

            if(!isset($filelist) || empty($filelist) || empty($files)) {
                return;
            }

            // $files holds bookmark names (the edit select is keyed by name).
            // Remove the matching membership bookmarks from every same-named
            // list in this vault so the logical FileList stays consistent.
            $sameNamedIds = FileList::where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->where('name', $filelist->name)
                ->pluck('id');

            $count = Bookmark::where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->whereIn('filelist_id', $sameNamedIds)
                ->whereIn('name', $files)
                ->delete();

            Notification::make()
                ->title($count > 1 ? __('vault.tool_bookmarks_removed_plural', ['count' => $count]) : __('vault.tool_bookmarks_removed', ['count' => $count]))
                ->icon('phosphor-bell-ringing-duotone')
                ->iconColor('primary')
                ->send();

            // refresh component to reflect count change.
            $this->dispatch('refresh-this');
        }

        #[On('livewire:add-bookmark')]
        public function addBookmark($name, $fullpath, $filetype, $icon)
        {
            $bookmark = Bookmark::where('name', $name)
                ->where('fullpath', $fullpath)
                ->where('filetype', $filetype)
                ->where('user_id', auth()->user()->id)
                ->where('case_id', $this->caseid)
                ->where('dir_id', $this->did)
                ->where('vault_id', $this->vid)
                ->whereNull('filelist_id')
                ->first();

            if(!empty($bookmark)) {
                Notification::make()
                    ->title(__('vault.tool_bookmark_exists', ['name' => $name]))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('warning')
                    ->send();
                return;
            }

            Bookmark::create([
                'user_id'  => auth()->user()->id,
                'case_id'  => $this->caseid,
                'vault_id' => $this->vid,
                'dir_id'   => $this->did,
                'name'     => $name,
                'fullpath' => $fullpath,
                'filetype' => $filetype,
                'icon'     => $icon,
            ]);

            Notification::make()
                ->title(__('vault.tool_bookmark_added', ['name' => $name]))
                ->icon('phosphor-bell-ringing-duotone')
                ->iconColor('primary')
                ->send();
            return;
        }

        #[On('del-bookmark')]
        public function delBookmark($id)
        {
            $bookmark = Bookmark::where('id', $id)
                ->where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->whereNull('filelist_id')
                ->first();

            if(isset($bookmark)) {
                // The toolbar shows one button per logical bookmark, so deleting
                // it removes every per-case copy in this vault.
                Bookmark::where('user_id', auth()->user()->id)
                    ->where('vault_id', $this->vid)
                    ->whereNull('filelist_id')
                    ->where('name', $bookmark->name)
                    ->where('fullpath', $bookmark->fullpath)
                    ->where('filetype', $bookmark->filetype)
                    ->delete();
            }
        }

        public function getCaseinfo($caseid): Schema
        {
            $schema = new Schema;
            return $schema
                ->record(SupportCase::where('id', $caseid)->first())
                ->components([
                    Grid::make(8)
                        ->schema([
                            TextEntry::make('case')
                                ->size(TextSize::Small)
                                ->color($this->color)
                                ->label(__('vault.tool_case_label')),
                            TextEntry::make('serial')
                                ->size(TextSize::Small)
                                ->label(__('vault.tool_serial_label')),
                            TextEntry::make('customer')
                                ->size(TextSize::Small)
                                ->label(__('vault.tool_customer_label')),
                            TextEntry::make('host')
                                ->size(TextSize::Small)
                                ->label(__('vault.tool_host_label')),
                            TextEntry::make('date')
                                ->size(TextSize::Small)
                                ->date('d/M/Y')
                                ->label(__('vault.tool_report_date_label')),
                            TextEntry::make('label')
                                ->size(TextSize::Small)
                                ->label(__('vault.tool_label_label')),
                            /*
                            TextEntry::make('version')
                                ->size(TextSize::Small)
                                ->label('Version'),
                                */
                            TextEntry::make('status')
                                ->color(fn (string $state): string => match ($state) {
                                    'DONE' => 'gray',
                                    'BLOCKED' => 'warning',
                                    'WAIT' => 'info',
                                    'WAITCUST' => 'info',
                                    'CLOSED' => 'danger',
                                    'SOLVED' => 'danger',
                                    'OPEN' => 'primary',
                                    'REOPEN' => 'primary',
                                    default => "gray",
                                })
                                ->size(TextSize::Small)
                                ->badge(),
                            TextEntry::make('os_version')
                                ->icon(fn ($record): string => $record->os_icon)
                                ->label(__('vault.tool_os_label'))
                                ->limit(12)
                                ->tooltip(fn ($state): string => $state)
                                ->copyable()
                                ->copyMessage('OS version copied')
                                ->copyMessageDuration(1500)
                                ->formatStateUsing(function($state): string {
                                    $temp = explode(" ", $state);
                                    $small = array_slice($temp, 0, 2);
                                    return implode(" ", $small) . " ...";
                                })
                                ->size(TextSize::Small)
                                ->iconColor('info'),
                        ])
                    ]);
        }

        public function getReportInfo(): Schema
        {
            $case  = SupportCase::where('id', $this->caseid)->first();
            $owner = $case ? User::find($case->owner) : null;

            $creq = ($this->vid && $this->did)
                ? ContentsRequest::where('vault_id', $this->vid)
                    ->where('dir_id', $this->did)
                    ->where('file_id', 0)
                    ->first()
                : null;

            $isShared = $creq && in_array($creq->status, ['SHARED', 'LOCKED']);

            $schema = new Schema;

            return $schema
                ->record($case)
                ->components([
                    Grid::make(10)
                        ->schema([
                            TextEntry::make('sharing')
                                ->label(__('vault.tool_sharing_label'))
                                ->state($isShared ? 'SHARED' : 'PRIVATE')
                                ->badge()
                                ->color($isShared ? 'success' : 'gray')
                                ->size(TextSize::Small),
                            TextEntry::make('owner')
                                ->label(__('vault.tool_owner_label'))
                                ->state($owner?->name ?? 'Unknown')
                                ->size(TextSize::Small),
                            TextEntry::make('group')
                                ->label(__('vault.tool_group_label'))
                                ->size(TextSize::Small),
                            TextEntry::make('perms')
                                ->label(__('vault.tool_permissions_label'))
                                ->size(TextSize::Small),
                            TextEntry::make('compression')
                                ->label(__('vault.tool_compression_label'))
                                ->badge()
                                ->color('info')
                                ->hidden(fn ($record) => empty($record?->compression))
                                ->size(TextSize::Small),
                            TextEntry::make('secured')
                                ->label(__('vault.tool_secured_label'))
                                ->state(fn ($record) => 'secured')
                                ->badge()
                                ->color('success')
                                ->icon('phosphor-lock-key-duotone')
                                ->hidden(fn ($record) => ! $record?->secured)
                                ->size(TextSize::Small),
                            TextEntry::make('gpg')
                                ->label(__('vault.tool_gpg_label'))
                                ->state(fn ($record) => 'gpg')
                                ->badge()
                                ->color('success')
                                ->icon('phosphor-key-duotone')
                                ->hidden(fn ($record) => ! $record?->gpg)
                                ->size(TextSize::Small),
                            TextEntry::make('tar')
                                ->label(__('vault.tool_tar_label'))
                                ->state(fn ($record) => 'tar')
                                ->badge()
                                ->color('info')
                                ->icon('phosphor-archive-duotone')
                                ->hidden(fn ($record) => ! $record?->tar)
                                ->size(TextSize::Small),
                            TextEntry::make('obfuscated')
                                ->label(__('vault.tool_obfuscated_label'))
                                ->state(fn ($record) => 'obfuscated')
                                ->badge()
                                ->color('warning')
                                ->icon('phosphor-eye-slash-duotone')
                                ->hidden(fn ($record) => ! $record?->obfuscated)
                                ->size(TextSize::Small),
                        ]),
                ]);
        }

        public function getFilelists(): array
        {
            // filelists buttons
            $filelists = [];

            // FileLists are case-independent: gather every list in the vault and
            // collapse same-named lists (one per case) into one logical list,
            // whose contents are the union (deduped) of their bookmarks.
            $allLists = FileList::where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->orderBy('name', 'asc')
                ->get();

            foreach($allLists->unique('name') as $filelist) {
                $sameNamedIds = $allLists->where('name', $filelist->name)->pluck('id');

                $files = [];
                $tooltip = "<ul>";
                foreach(Bookmark::where('user_id', auth()->user()->id)
                    ->where('vault_id', $this->vid)
                    ->whereIn('filelist_id', $sameNamedIds)
                    ->orderBy('name', 'asc')
                    ->get()
                    ->unique(fn ($b) => $b->name.'|'.$b->fullpath) as $record) {
                    $files[] = [
                        'name' => $record->name,
                        'path' => $record->fullpath,
                    ];
                    $tooltip .= "<li>{$record->name}</li>";
                }
                $tooltip .= "</ul>";

                $count = count($files);
                $files = base64_encode(json_encode($files));

                $filelists[] = Flex::make([
                    Action::make($filelist->name)
                        ->badge($count)
                        ->badgeColor('info')
                        ->extraAttributes([
                            'class' => 'w-32',
                            'onclick' => "window.sosViewer.filelistProcessor('$files')",
                        ])
                        ->outlined()
                        ->after(function() {
                            $this->dispatch('sidebar-toggled');
                        })
                        ->iconSize(IconSize::Large)
                        ->color($this->color)
                        ->label($filelist->name)
                        ->tooltip(new \Illuminate\Support\HtmlString($tooltip))
                        ->icon($filelist->icon)
                        ->disabled(!$filelist->enabled),
                     Action::make("edit-{$filelist->name}")
                        ->iconButton()
                        ->icon('phosphor-note-pencil-duotone')
                        ->outlined()
                        ->iconSize(IconSize::Medium)
                        ->color('primary')
                        ->tooltip(__('vault.tool_edit_filelist_heading', ['name' => $filelist->name]))
                        ->disabled(!$filelist->enabled)
                        ->modalWidth('2xl')
                        ->modalIcon('phosphor-note-pencil-duotone')
                        ->modalHeading(__('vault.tool_edit_filelist_heading', ['name' => $filelist->name]))
                        ->modalSubmitActionLabel(__('vault.tool_rename_action'))
                        ->form([
                             Section::make(__('vault.tool_del_bookmarks_heading'))
                                ->description(__('vault.tool_del_bookmarks_description'))
                                ->visible($count > 1)
                                ->schema([
                                    Select::make('bookmarks2del')
                                        ->label(__('vault.tool_filelist_contains', ['count' => $count]))
                                        ->multiple()
                                        ->placeholder(__('vault.tool_select_bookmarks'))
                                        ->searchable(false)
                                        ->options(Bookmark::where('user_id', auth()->user()->id)
                                                ->where('vault_id', $this->vid)
                                                ->whereIn('filelist_id', $sameNamedIds)
                                                ->orderBy('name', 'asc')
                                                ->pluck('name', 'name')
                                        ),
                                    Action::make('Delete')
                                        ->cancelParentActions("edit-{$filelist->name}")
                                        ->color('danger')
                                        ->action(function ($get) use ($filelist) {
                                            $bookmarks = $get('bookmarks2del');
                                            $this->editFileList($filelist->id, $bookmarks);
                                        }),
                                ]),
                             Section::make(__('vault.tool_rename_heading'))
                                ->description(__('vault.tool_rename_description'))
                                ->schema([
                                    TextInput::make('filelistName')
                                        ->label(__('vault.tool_new_name_label'))
                                        ->placeholder(__('vault.tool_type_name'))
                                        ->type('search')
                                        ->required()
                                        ->alphaDash()
                                        ->maxLength(16)
                                        ->minLength(3)
                                        ->rules([
                                            fn ($record) => function (string $attribute, $value, $fail) use ($filelist) {
                                                $this->filelistName = '';
                                                if ($value === $filelist->name) {
                                                    $fail('The new name must be different from the current one.');
                                                }
                                            },
                                            fn ($record) => function (string $attribute, $value, $fail) use ($filelist) {
                                                $existing = FileList::where('name', $value)
                                                    ->where('user_id', auth()->user()->id)
                                                    ->where('vault_id', $this->vid)
                                                    ->first();

                                                if(isset($existing) || !empty($existing)) {
                                                    $fail('A file list with that same name already exists.');
                                                }
                                            },
                                        ])
                                        ->columnSpan(2),
                                ]),
                        ])
                        ->after(function() {
                            $this->dispatch('sidebar-toggled');
                        })
                        ->action(function (array $data) use ($filelist) {
                            $this->renameFileList($filelist->id, $data['filelistName']);
                        }),
                     Action::make('delete_' . $filelist->name)
                        ->iconButton()
                        ->icon('phosphor-trash-duotone')
                        ->outlined()
                        ->iconSize(IconSize::Medium)
                        ->color('danger')
                        ->tooltip(__('vault.tool_delete_filelist_heading', ['name' => $filelist->name]))
                        ->disabled(!$filelist->enabled)
                        ->action(fn () => $this->dispatch('del-filelist', id: $filelist->id))
                        ->after(function() {
                            $this->dispatch('sidebar-toggled');
                        })
                        ->requiresConfirmation()
                        ->modalHeading(__('vault.tool_delete_filelist_heading', ['name' => $filelist->name]))
                        ->modalDescription(__('vault.tool_delete_filelist_description'))
                        ->modalSubmitActionLabel(__('vault.tool_delete_filelist_confirm')),
                    ])
                    ->extraAttributes([
                        'class' => 'justify-center items-center gap-1',
                    ])
                    ->grow(false);
            }
            return $filelists;
        }

        public function getBookmarks(): array
        {
            // bookmarks buttons
            $bookmarks = [];
            // Bookmarks are case-independent: scope to the whole vault (not a
            // single case/dir) and collapse the per-case duplicate rows into one
            // logical entry keyed by (name, fullpath, filetype).
            foreach(Bookmark::where('user_id', auth()->user()->id)
                ->where('vault_id', $this->vid)
                ->whereNull('filelist_id')
                ->orderBy('name', 'asc')
                ->get()
                ->unique(fn ($b) => $b->name.'|'.$b->fullpath.'|'.$b->filetype) as $bookmark) {
                $bookmarks[] = Flex::make([
                    Action::make($bookmark->name)
                        ->badge()
                        ->extraAttributes([
                            'class' => 'w-24',
                            'onclick' => "window.sosViewer.bookmarkProcessor('$bookmark->name','$bookmark->fullpath')",
                        ])
                        ->after(function() {
                            $this->dispatch('sidebar-toggled');
                        })
                        ->outlined()
                        ->iconSize(IconSize::Large)
                        ->color('warning')
                        ->label($bookmark->name)
                        ->tooltip(fn() => ($bookmark->filetype == 'd' ? 'directory' : 'file') . ' ' . $bookmark->name)
                        ->icon($bookmark->icon)
                        ->disabled(!$bookmark->enabled),
                     Action::make('delete_' . $bookmark->name)
                        ->iconButton()
                        ->icon('phosphor-trash-duotone')
                        ->outlined()
                        ->iconSize(IconSize::Small)
                        ->color('danger')
                        ->tooltip(__('vault.browser_delete_bookmark', ['name' => $bookmark->name]))
                        ->disabled(!$bookmark->enabled)
                        ->after(function() {
                            $this->dispatch('sidebar-toggled');
                        })
                        ->action(fn () => $this->dispatch('del-bookmark', id: $bookmark->id)),
                    ])
                    ->extraAttributes([
                        'class' => 'justify-center items-center gap-1',
                    ])
                    ->grow(false);
            }
            return $bookmarks;
        }

        public function getTools(): array
        {
            // tools buttons
            $tools = [];
            foreach(Tools::query()->where('showInMenu', true)->get() as $tool) {
                if($tool->url) {
                    $featureName = match($tool->type) {
                        'advanced' => 'Advanced Tools',
                        'special'  => 'Special Tools',
                        default    => 'Basic Tools',
                    };
                    $hasPlanAccess = checkAccess(auth()->user(), $featureName);
                    $isEnabled = $tool->enabled && $hasPlanAccess;
                    $tools[] = Action::make($tool->name)
                        ->extraAttributes([
                            'class' => 'w-32',
                        ])
                        ->action(function ($record) use ($tool) {
                            $tabName = "SOS {$tool->name}";
                            $url = $tool->url;
                            $url = str_replace("[vid]", $this->vid, $url);
                            $url = str_replace("[did]", $this->did, $url);
                            $url = str_replace("[caseid]", $this->caseid, $url);
                            $this->dispatch('checkTab', ['url' => $url, 'tabName' => $tabName]);
                        })
                        ->after(function() {
                            $this->dispatch('sidebar-toggled');
                        })
                        ->openUrlInNewTab()
                        ->badge()
                        ->outlined()
                        ->iconSize(IconSize::Large)
                        ->color($isEnabled ? $this->color : 'gray')
                        ->label($tool->title)
                        ->icon($tool->icon)
                        ->disabled(! $isEnabled)
                        ->tooltip($isEnabled ? $tool->tooltip : __('vault.tool_upgrade_required'));
                }
            }

            // Directory sharing buttons (owner only)
            $tools[] = Action::make('shareDir')
                ->visible(fn () => $this->sme === 0 && ! $this->isDirShared)
                ->extraAttributes(['class' => 'w-32'])
                ->badge()
                ->outlined()
                ->iconSize(IconSize::Large)
                ->color('primary')
                ->label(__('vault.tool_share_label'))
                ->icon('phosphor-share-fat-duotone')
                ->tooltip(__('vault.tool_share_tooltip'))
                ->modalHeading(__('vault.tool_share_sos_report'))
                ->modalContent(function () {
                    $url = $this->getDirModalContent();
                    return new \Illuminate\Support\HtmlString(
                        '<div class="flex flex-col gap-4 p-4">'
                        . '<p class="text-sm text-gray-600 dark:text-gray-300">Share URL:</p>'
                        . '<input id="dir-share-url" type="text" readonly value="' . e($url) . '"'
                        . ' class="w-full rounded border border-gray-300 dark:border-zinc-600 bg-gray-50 dark:bg-zinc-800 px-3 py-2 text-sm font-mono" />'
                        . '</div>'
                    );
                })
                ->modalSubmitActionLabel(__('vault.tool_copy_to_clipboard'))
                ->schema([
                    \Filament\Forms\Components\Toggle::make('isPublic')
                        ->label(__('vault.tool_share_public_label'))
                        ->helperText(__('vault.tool_share_public_tooltip'))
                        ->visible(fn () => auth()->user()->hasRole('admin')),
                ])
                ->action(function (array $data) {
                    $this->shareDir($data['isPublic'] ?? false);
                })
                ->after(function () {
                    $this->dispatch('sidebar-toggled');
                });

            $tools[] = Action::make('urilink')
                ->visible(fn () => $this->sme === 0 && $this->isDirShared)
                ->extraAttributes(['class' => 'w-32'])
                ->badge()
                ->outlined()
                ->iconSize(IconSize::Large)
                ->color('info')
                ->label(__('vault.tool_urilink_label'))
                ->icon('phosphor-link-simple-duotone')
                ->tooltip(__('vault.tool_urilink_tooltip'))
                ->modalHeading(__('vault.tool_shared_sos_url'))
                ->modalContent(function () {
                    $url = $this->dirShareUrl;
                    return new \Illuminate\Support\HtmlString(
                        '<div class="flex flex-col gap-4 p-4">'
                        . '<p class="text-sm text-gray-600 dark:text-gray-300">Share URL:</p>'
                        . '<input id="dir-share-url" type="text" readonly value="' . e($url) . '"'
                        . ' class="w-full rounded border border-gray-300 dark:border-zinc-600 bg-gray-50 dark:bg-zinc-800 px-3 py-2 text-sm font-mono" />'
                        . '</div>'
                    );
                })
                ->modalSubmitActionLabel(__('vault.tool_copy_to_clipboard'))
                ->action(function () {
                    $this->copyDirUrl();
                })
                ->after(function () {
                    $this->dispatch('sidebar-toggled');
                });

            $tools[] = Action::make('unshareDir')
                ->visible(fn () => $this->sme === 0 && $this->isDirShared)
                ->disabled(fn () => $this->isCasePublic)
                ->tooltip(fn () => $this->isCasePublic ? __('vault.tool_unshare_disabled_public') : __('vault.tool_unshare_tooltip'))
                ->extraAttributes(['class' => 'w-32'])
                ->badge()
                ->outlined()
                ->iconSize(IconSize::Large)
                ->color('danger')
                ->label(__('vault.tool_unshare_label'))
                ->icon('phosphor-share-fat-duotone')
                ->tooltip(__('vault.tool_unshare_tooltip'))
                ->requiresConfirmation()
                ->modalHeading(__('vault.tool_unshare_heading'))
                ->modalDescription(__('vault.tool_unshare_description'))
                ->modalSubmitActionLabel(__('vault.tool_unshare_confirm'))
                ->action(function () {
                    $this->unshareDir();
                })
                ->after(function () {
                    $this->dispatch('sidebar-toggled');
                });

            return $tools;
        }

        public function mkSection($parent): array
        {
            $section = [];
            switch($parent) {
                case "sosBrowser":
                    $section = [
                        Grid::make(18) //! important
                        ->extraAttributes([
                        ])
                        ->schema([
                            ViewEntry::make('breadcrumbs')
                                ->columnSpan(9)
                                ->extraAttributes([
                                ])
                                ->view('filament.components.breadcrumbs'),
                            TextInput::make('')
                                ->columnSpan(6)
                                ->view('filament.components.file-search'),
                            Select::make('caseSwitcher')
                                ->columnSpan(3)
                                ->extraAttributes([
                                    'class' => 'mb-3 ring-4 ring-primary-600 dark:ring-primary-400 rounded-lg',
                                ])
                                ->hiddenLabel()
                                ->placeholder(__('vault.file_select_case'))
                                ->searchable(false)
                                ->live()
                                ->afterStateUpdated(function ($state) {
                                    $case = SupportCase::find($state);
                                    if ($case && $case->is_public && $case->vault_id !== $this->vid) {
                                        $creq = ContentsRequest::where('vault_id', $case->vault_id)
                                            ->where('dir_id', $case->file_id)
                                            ->where('file_id', 0)
                                            ->first();
                                        if ($creq?->url) {
                                            return redirect()->to($creq->url);
                                        }
                                    }

                                    return redirect()->to("/sosbrowser/$state");
                                })
                                ->options($this->caseOptions)
                                ->getOptionLabelUsing(function ($value): ?string {
                                    return $this->caseOptions[$value] ?? null;
                                })
                                ->allowHtml(),
                        ]),
                    ];
                break;
                case "Compare":
                    $section = [
                        Grid::make(30) //! important
                        ->extraAttributes([
                        ])
                        ->schema([
                            ViewEntry::make('breadcrumbs')
                                ->columnSpan(14)
                                ->extraAttributes([
                                ])
                                ->view('filament.components.breadcrumbs'),
                            TextInput::make('')
                                ->columnSpan(8)
                                ->view('filament.components.file-search'),
                            Select::make('caseid2')
                                ->columnSpan(5)
                                ->extraAttributes([
                                    'class' => 'mb-3 ring-4 ring-primary-600 dark:ring-primary-400 rounded-lg',
                                ])
                                ->hiddenLabel()
                                ->placeholder(__('vault.file_select_case'))
                                ->searchable(false)
                                ->live()
                                ->afterStateUpdated(function ($state, $livewire) {
                                    $livewire->dispatch('case-selection-sequece', cid: $state);
                                    // this line prevents the Select to keep showing the selected case all the time
                                    // but renders the Case Right button invisible. So it's commented
                                    //$livewire->caseid2 = null;
                                })
                                ->options($this->caseOptions)
                                ->getOptionLabelUsing(fn ($value): ?string => $this->caseOptions[$value] ?? null)
                                ->allowHtml(),
                        ]),
                    ];
                break;
                case "STIG":
                break;
            }

            return $section;
        }

        public function schema(Schema $schema): Schema
        {
            $tools     = $this->getTools();
            $filelists = $this->getFilelists();
            $bookmarks = $this->getBookmarks();

            return $schema
                ->record(SupportCase::where('id', $this->caseid)->first())
                ->components([
                    Fieldset::make('')
                    ->contained(false)
                    ->extraAttributes([
                        'class' => 'my-0 py-0',
                    ])
                    ->columns(1)
                    ->schema([
                        Section::make('')
                            ->contained(false)
                            ->extraAttributes([
                                'class' => 'text-2xl my-1 py-1',
                            ])
                            ->heading(
                                $this->isCasePublic
                                    ? new \Illuminate\Support\HtmlString(
                                        '<span class="inline-flex items-center gap-2">'
                                        . '<span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold border border-info-500 text-info-600 dark:border-info-400 dark:text-info-400 rounded-full">'
                                        . e(__('vault.tool_share_public_label'))
                                        . '</span>'
                                        . e($this->title)
                                        . '</span>'
                                    )
                                    : $this->title
                            )
                            ->icon($this->icon)
                            ->iconSize(IconSize::Large)
                            ->iconColor($this->color)
                            ->compact(true)
                            ->collapsible()
                            ->schema([
                                Tabs::make('Tabs')
                                ->contained(false)
                                ->persistTab()
                                ->extraAttributes([
                                    'class' => 'my-0 py-0',
                                ])
                                ->id('soscompare-tab-order')
                                ->tabs([
                                    Tab::make(__('vault.tool_tab_report_info'))
                                        ->icon('phosphor-archive-duotone')
                                        ->extraAttributes([
                                            'class' => 'my-2 py-0',
                                        ])
                                        ->schema([
                                            Flex::make(fn ($record) => $this->getReportInfo())
                                                ->grow(true),
                                        ]),
                                    Tab::make(__('vault.tool_tab_case_info'))
                                        ->label(fn ($livewire): string => !empty($livewire->caseid2) ? __('vault.tool_tab_case_left_info') : __('vault.tool_tab_case_info'))
                                        ->extraAttributes([
                                            'class' => 'my-2 py-0',
                                        ])
                                        ->icon("phosphor-ticket-duotone")
                                        ->schema([
                                            Flex::make(fn ($record) => $this->getCaseinfo($record->id ?? null))
                                                ->grow(true),
                                        ]),
                                    Tab::make(__('vault.tool_tab_case_right_info'))
                                        ->extraAttributes([
                                            'class' => 'my-2 py-0',
                                        ])
                                        ->visible(fn ($livewire): bool => !empty($livewire->caseid2))
                                        ->icon("phosphor-ticket-duotone")
                                        ->schema([
                                            Flex::make(fn ($livewire) => $this->getCaseinfo($livewire->caseid2))
                                                ->grow(true),
                                        ]),
                                    Tab::make(__('vault.tool_tab_tools'))
                                        ->icon("phosphor-toolbox-duotone")
                                        ->schema([
                                            Flex::make($tools)
                                                ->grow(true),
                                        ]),
                                    Tab::make(__('vault.tool_tab_bookmarks'))
                                        ->extraAttributes([
                                            'wire:key' => 'bookmarks-tab',
                                        ])
                                        ->icon("phosphor-bookmarks-duotone")
                                        ->badge(fn (): int => count($bookmarks))
                                        ->badgeColor('warning')
                                        ->schema([
                                            Flex::make($bookmarks)
                                                ->extraAttributes([
                                                    'class' => 'flex-wrap',
                                                ])
                                                ->grow(false),
                                        ]),
                                    Tab::make(__('vault.tool_tab_file_lists'))
                                        ->icon("phosphor-list-heart-duotone")
                                        ->badge(fn (): int => count($filelists))
                                        ->badgeColor('info')
                                        ->schema([
                                            Flex::make($filelists)
                                                ->extraAttributes([
                                                    'class' => 'flex-wrap',
                                                ])
                                                ->grow(false),
                                        ]),
                                ])
                                ->activeTab(1),
                            ]),
                            // this section depeneds on the parent component
                            Section::make('')
                                ->contained(false)
                                ->schema(fn ($record) => $this->mkSection($this->parent)),

                    ]),
                ]);
        }

    }
?>

<div>

        @if(isset($caseid))
            <x-filament-actions::modals />

            @script
            <script>
                $wire.on('refresh-this', window.sosViewer.filelistRefresh);
                $wire.on('reset-breadcrumbs', window.sosViewer.clearBreadCrumbs);
                $wire.on('reset-searchbox', window.sosViewer.clearSearchFile);
                $wire.on('copy-dir-to-clipboard', (event) => {
                    const url = event[0].url;
                    if (url && navigator.clipboard) {
                        navigator.clipboard.writeText(url).then(() => {
                            new FilamentNotification()
                                .title('{{ __('vault.tool_link_copied') }}')
                                .icon('phosphor-check-circle-duotone')
                                .iconColor('success')
                                .send();
                        });
                    }
                });
                $wire.on('checkTab', (event) => {
                    const { url, tabName } = event[0];
                    const mesg = new FilamentNotification()
                    let newWindow;
                    if(!window.sosViewer.checkTab(tabName)) {
                        mesg.title('Opening ' + tabName.replace(/SOS /,''))
                        mesg.icon('phosphor-bell-ringing-duotone')
                        mesg.iconColor('success')
                        newWindow = window.open(url, tabName);
                    } else {
                        mesg.title(tabName.replace(/SOS /,'') + ' is already open')
                        mesg.icon('phosphor-bell-ringing-duotone')
                        mesg.iconColor('warning')
                    }
                    mesg.send()
                    if (newWindow) {
                        newWindow.focus();
                    }
                });
            </script>
            @endscript

            <div class="fixed top-0 right-0 z-10 h-12 w-full bg-transparent pl-8">
                <div class="block z-10 h-4 w-full bg-zinc-50 dark:bg-zinc-800 "></div>
                <div class="block z-10 h-8 w-full bg-white   dark:bg-zinc-800 border-t-1 border-zinc-200 dark:border-zinc-700 "> </div>
            </div>

            <header id="tool-controls-content" class="fixed top-6 z-20 flex overflow-x-auto border rounded-lg px-4 pb-2 mr-5 bg-white dark:bg-zinc-900">
                {{ $this->schema }}

            </header>

            @if(!empty($fileData))
                @livewire('file-list-context-menu', ['fileData' => $fileData])
            @endif

            <div wire:click="$refresh" id="fileListRefresh" class="hidden"></div>

        @endif

</div>

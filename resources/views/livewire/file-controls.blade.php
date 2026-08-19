<?php
use App\Models\FileContent;
use App\Models\SupportCase;
use App\Models\Tools;
use App\Models\User;
use App\Providers\VaultTools;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\IconSize;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;

middleware('auth');
name('file-controls');

new class extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions, InteractsWithSchemas;

    public ?array $data = [];

    public $caseid;

    public $fid;

    public $vid;

    public $did;

    public $parent;

    public $color;

    public $title;

    public $icon;

    public $filename;

    public $filepath;

    public $caseid2;

    public int $sme = 0;

    public bool $lineNumbersShown = true;

    public bool $isLocked = false;

    public bool $isOwner = false;

    public bool $isShared = false;

    public bool $isTable = false;

    public bool $isLogFile = false;

    public int $totalLines = 0;

    public bool $isChunked = false;

    public int $currentLines = 0;

    public string $shareExpire = '';

    public string $ownerName = '';

    public string $shareUrl = '';

    public bool $rawMode = true;

    public bool $highlightEnabled = false;

    public $notes = '';

    public $info = '';

    public function mount($caseid, $fid, $parent, $color, int $sme = 0)
    {
        $this->sme = $sme;
        $this->info = '<div class="prose dark:text-zinc-400 text-md">' . __('vault.file_table_mode_info') . '</div>';

        if (! isset($caseid) || ! isset($fid) || ! isset($parent)) {
            Notification::make()
                ->title('Missing required parameters. Cannot continue.')
                ->icon('phosphor-bell-ringing-duotone')
                ->iconColor('danger')
                ->send();

            return false;
        }

        $this->caseid = $caseid;
        $this->fid = $fid;
        $this->parent = $parent;

        $case = SupportCase::where('id', $this->caseid)->first();

        if (! isset($case)) {
            $message = 'No case found. Cannot continue.';
            notifyError($message);

            return;
        }

        $this->vid = $case->vault_id;
        $this->did = $case->file_id;
        $this->isOwner = auth()->id() === (int) $case->owner;

        $metadata = FileContent::withParameters([
            'vid' => $this->vid,
            'did' => $this->did,
            'fid' => $this->fid,
            'cid' => $this->caseid,
            'format' => 'raw',
            'source' => 'file-controls-mount',
        ])
            ->where('case_id', $this->caseid)->first();

        if (isset($metadata)) {
            $this->isLocked = boolval($metadata->locked);
            $this->isShared = boolval($metadata->shared);
            $this->isTable = boolval($metadata->isTable);
            $this->rawMode = ! boolval($metadata->isTable);
            $this->shareUrl = $metadata->url ?? '';

            session(['offset' => 0]);
            session(['lines' => 0]);
            session(['chunked' => $metadata->chunked]);
            session(['chunkCount' => 0]);
            $this->isLogFile  = boolval($metadata->isLogFile);
            $this->totalLines = (int) ($metadata->totalLines ?? 0);
            $this->isChunked  = boolval($metadata->chunked);
            $this->shareExpire = $metadata->expire
                ? Carbon::parse($metadata->expire)->format('Y/M/d')
                : '';

            $ownerId = SupportCase::where('id', $this->caseid)->value('owner');
            $this->ownerName = User::where('id', $ownerId)->value('name') ?? 'Unknown';

            session(['isLogFile' => $metadata->isLogFile]);

            $filepath = explode('/', $metadata->name);
            $filename = explode('_', array_pop($filepath));
            $this->filename = $filename[0];
            $this->filepath = "/{$metadata->path}{$metadata->name}";

        }

        if (! isset($color)) {
            $this->color = 'warning';
        } else {
            $this->color = $color;
        }

        $tool = Tools::query()->where('name', $parent)
            ->where('enabled', true)
            ->first();

        if (! isset($tool)) {
            Notification::make()
                ->title('Tool not found. Cannot continue.')
                ->icon('phosphor-bell-ringing-duotone')
                ->iconColor('danger')
                ->send();

            return false;
        }

        $this->title = "{$tool->title} {$this->filepath}";
        $this->icon = $tool->icon;

    }

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

    #[On('setErrorState')]
    public function setErrorState()
    {
        $this->errorState = true;
    }

    #[On('log-range-ready')]
    public function onLogRangeReady(): void
    {
        $tz      = session('tz', 'UTC');
        $iniDate = session('ini_date');
        $iniTime = session('ini_time', '00:00:00');
        $finDate = session('fin_date');
        $finTime = session('fin_time', '23:59:59');

        if ($iniDate && $finDate) {
            $logStart = Carbon::parse("{$iniDate} {$iniTime}", $tz)->format('Y-m-d H:i:s');
            $logEnd   = Carbon::parse("{$finDate} {$finTime}", $tz)->format('Y-m-d H:i:s');

            $this->js("window.dispatchEvent(new CustomEvent('log-range-updated',{detail:{logStart:'{$logStart}',logEnd:'{$logEnd}'}}))");
        }
    }

    #[On('load-more')]
    public function loadMore()
    {
        // load another chunk of file
        if (session('chunked')) {
            if (session('chunkCount') == 0) {
                $n = session('chunkCount');
                $n++;
                session(['chunkCount' => $n]);
                $this->dispatch('close-modal', id: 'load-more');
            } else {
                $this->dispatch('load-chunk', ['offset' => session('offset')])->to('file-contents');
            }
        }
    }

    #[On('update-offset')]
    public function updateOffset($data)
    {
        // update the FileInfo tab on the file-controls component
        session(['offset' => $data['offset']]);
        session(['chunkSize' => $data['chunkSize']]);

        $lines = session('lines') + $data['lines'];
        session(['lines' => $lines]);
        $this->currentLines = $lines;
    }

    public function downloadFile()
    {

        if (! (isset($this->vid) && isset($this->did) && isset($this->fid))) {
            $message = 'Missing parameters. Cannot continue.';
            notifyError($message);

            return;
        }

        $vtools = new VaultTools(resolveVaultUser($this->vid, $this->caseid, $this->did, $this->fid), $this->vid);
        if (! isset($vtools)) {
            $message = "Couldn't access your vault. Cannot continue.";
            notifyError($message);

            return;
        }

        if ($vtools->getVaultId() != $this->vid) {
            $message = 'Wrong vault provided. Cannot continue.';
            notifyError($message);

            return;
        }

        if (! $vtools->isOpen()) {
            $message = 'Your vault is closed. Cannot continue.';
            notifyError($message);

            return;
        }

        $dir = $vtools->getDirById($this->did);
        $mountp = $vtools->getMountPoint();

        if (! $dir) {
            $message = "Couldn't find the directory. Cannot continue.";
            notifyError($message);

            return;
        }

        $tree = $vtools->getContents("{$mountp}/{$dir->name}");
        if ($tree) {
            $file = $vtools->find_node_by_attr($tree->nodes[0]->nodes, 'id', $this->fid);
        }

        if (! $file) {
            $message = "Couldn't find the file. Cannot continue.";
            notifyError($message);

            return;
        }

        $path = $file->path && ! $file->realpath ? $file->path : $file->realpath;

        $filepath = "{$mountp}/{$dir->name}/{$path}/{$file->name}";

        if (! file_exists($filepath)) {
            $message = "Couldn't find the actual file. Cannot continue.";
            notifyError($message);

            return;
        } else {
            $filename = basename($filepath);
            $uid = auth()->user()->id;
            $gid = auth()->user()->id;

            $message = 'File download success';
            $payload = (object) [
                'message' => $message,
                'filename' => $filename,
                'dir_id' => $this->did,
                'file_id' => $this->fid,
            ];
            addEvent($payload, 'DOWLOAD', 'SUCCESS', 'NORMAL', $this->caseid, $this->vid, $uid, $gid);

            $headers = [
                'Content-Description' => 'File Transfer',
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => "attachment; filename={$filename}",
                'Expires' => 0,
                'Cache-Control' => 'must-revalidate',
                'Pragma' => 'public',
                'Content-Length' => filesize($filepath),
            ];

            return response()->streamDownload(function () use ($filepath) {
                readfile($filepath);
            }, $filename, $headers);
        }
    }

    public function getModalContent(string $value): Htmlable
    {
        if (! $value) {
            // No ContentsRequest yet — create it so we have a stable URL.
            $metadata = FileContent::withParameters([
                'vid' => $this->vid,
                'did' => $this->did,
                'fid' => $this->fid,
                'cid' => $this->caseid,
                'format' => 'raw',
                'source' => 'file-controls-share-init',
            ])->where('case_id', $this->caseid)->first();

            if ($metadata) {
                $metadata->setRows([]); // creates ContentsRequest + Annotation if absent

                $refreshed = FileContent::withParameters([
                    'vid' => $this->vid,
                    'did' => $this->did,
                    'fid' => $this->fid,
                    'cid' => $this->caseid,
                    'format' => 'raw',
                    'source' => 'file-controls-share-url',
                ])->where('case_id', $this->caseid)->first();

                $value = $refreshed?->url ?? '';
                $this->shareUrl = $value;
            }
        }

        if ($value) {
            $escaped = e($value);

            return new HtmlString(
                "<div class=\"text-sm text-zinc-600 dark:text-zinc-300 font-mono break-all p-3 bg-zinc-100 dark:bg-zinc-800 rounded\">{$escaped}</div>"
            );
        }

        return new HtmlString(
            '<div class="text-sm text-zinc-500 dark:text-zinc-400">Could not generate share link. Please try again.</div>'
        );
    }

    public function toggleLineNumbers()
    {
        $this->dispatch('toggle-line-numbers');
        $this->lineNumbersShown = ! $this->lineNumbersShown;
    }

    public function addNote()
    {
        $this->dispatch('add-note');
    }

    #[On('livewire:note-count')]
    public function noteCount($notes)
    {
        $this->notes = $notes;
    }

    public function toggleHighlight()
    {
        $this->highlightEnabled = ! $this->highlightEnabled;
        $this->dispatch('toggle-highlight');
    }

    public function shareFile(): void
    {
        if (! \App\Services\VaultAccess::canManage(auth()->user(), $this->vid)) {
            return;
        }

        $this->isShared = true;

        $metadata = FileContent::withParameters([
            'vid' => $this->vid,
            'did' => $this->did,
            'fid' => $this->fid,
            'cid' => $this->caseid,
            'format' => 'raw',
            'source' => 'file-controls-share',
        ])->where('case_id', $this->caseid)->first();

        if (! $metadata) {
            return;
        }

        $metadata->setRows([
            'shared' => 'SHARED',
            'astatus' => 'SHARED',
            'expire' => Carbon::now()->addDays(7)->format('Y-m-d H:i:s'),
        ]);

        $refreshed = FileContent::withParameters([
            'vid' => $this->vid,
            'did' => $this->did,
            'fid' => $this->fid,
            'cid' => $this->caseid,
            'format' => 'raw',
            'source' => 'file-controls-share-url',
        ])->where('case_id', $this->caseid)->first();

        $this->shareUrl = $refreshed?->url ?? '';

        if ($this->shareUrl) {
            $this->dispatch('copy-to-clipboard', ['url' => $this->shareUrl]);
        }

        Notification::make()
            ->title(__('vault.file_page_shared'))
            ->success()
            ->body(__('vault.file_link_copied_body'))
            ->send();

        $uid = auth()->id() ?? 0;
        $payload = (object) [
            'message' => 'file shared',
            'name' => $this->filename,
        ];
        addEvent($payload, 'SHARE_FILE', 'SUCCESS', 'NORMAL', $this->caseid, $this->vid, $uid, $uid);
    }

    public function unshareFile(): void
    {
        if (! \App\Services\VaultAccess::canManage(auth()->user(), $this->vid)) {
            return;
        }

        $this->isShared = false;

        $metadata = FileContent::withParameters([
            'vid' => $this->vid,
            'did' => $this->did,
            'fid' => $this->fid,
            'cid' => $this->caseid,
            'format' => 'raw',
            'source' => 'file-controls-unshare',
        ])->where('case_id', $this->caseid)->first();

        if (! $metadata) {
            return;
        }

        $metadata->setRows([
            'shared' => 'PRIVATE',
            'astatus' => 'PRIVATE',
        ]);

        Notification::make()
            ->title(__('vault.file_page_unshared'))
            ->success()
            ->body(__('vault.file_no_external_access'))
            ->send();

        $uid = auth()->id() ?? 0;
        $payload = (object) [
            'message' => 'file unshared',
            'name' => $this->filename,
        ];
        addEvent($payload, 'UNSHARE_FILE', 'SUCCESS', 'NORMAL', $this->caseid, $this->vid, $uid, $uid);
    }

    public function copyUrl(): void
    {
        if ($this->shareUrl) {
            $this->dispatch('copy-to-clipboard', ['url' => $this->shareUrl]);
        }

        Notification::make()
            ->title(__('vault.file_link_copied_title'))
            ->success()
            ->body(__('vault.file_share_link_body'))
            ->send();
    }

    #[On('livewire:save-annotations')]
    public function saveAnnotations($acetate, $title, $locked, $status)
    {

        // saves annotations and highlighted text
        $case = SupportCase::where('id', $this->caseid)->first();

        if (! isset($case)) {
            $message = 'No case found. Cannot continue.';
            notifyError($message);

            return;
        }

        $this->vid = $case->vault_id;
        $this->did = $case->file_id;

        $metadata = FileContent::withParameters([
            'vid' => $this->vid,
            'did' => $this->did,
            'fid' => $this->fid,
            'cid' => $this->caseid,
            'format' => 'raw',
            'source' => 'file-controls-save-annotations',
        ])
            ->where('case_id', $this->caseid)->first();

        if (isset($metadata)) {
            // needs validation here

            $newdata = [
                'acetate' => $acetate,
                'title' => $title,
                'locked' => $locked,
                'status' => $status,
            ];

            $metadata->setRows($newdata);

        }
    }

    public function toggleLock($record)
    {
        $this->isLocked = ! $this->isLocked;

        // update status...
        if (isset($record)) {
            $data = [
                'locked' => ! $this->isLocked ? 'SHARED' : 'LOCKED',
            ];

            $record->setRows($data);

            Notification::make()
                ->title(fn (): string => $this->isLocked ? __('vault.file_page_unlocked') : __('vault.file_page_locked'))
                ->success()
                ->body(fn (): string => ! $this->isLocked ? __('vault.file_external_allowed') : __('vault.file_external_not_allowed'))
                ->send();

        }
    }

    public function toggleRaw()
    {
        $this->rawMode = ! $this->rawMode;
        // to the browser
        $this->dispatch('toggle-raw', ['rawMode' => $this->rawMode]);

        // to parent component
        $this->dispatch('toggle-raw-mode');
    }

    public function getFileTools(): array
    {
        // tools buttons
        $tools = [];

        $tools[] = Action::make('linenu')
            ->disabled(fn () => ! $this->rawMode)
            ->icon('phosphor-hash-duotone')
            ->extraAttributes([
                'class' => 'w-32',
            ])
            ->button()
            ->outlined()
            ->color(fn (): string => $this->lineNumbersShown ? 'warning' : 'gray')
            ->label(__('vault.file_lines_label'))
            ->tooltip(__('vault.file_lines_tooltip'))
            ->action(function (Action $action, array $data) {
                $this->toggleLineNumbers();
            });

        $tools[] = Action::make('annotation')
            ->disabled(fn () => (! $this->rawMode && ! $this->isTable) || ($this->sme > 0 && $this->isLocked && ! $this->isOwner))
            ->icon('phosphor-note-duotone')
            ->extraAttributes([
                'class' => 'w-32',
            ])
            ->button()
            ->outlined()
            ->badge($this->notes)
            ->badge(fn (): string => $this->notes)
            ->badgeColor('danger')
            ->color('warning')
            ->color(fn (): string => ($this->notes > 0) ? 'warning' : 'gray')
            ->label(__('vault.file_notes_label'))
            ->tooltip(__('vault.file_notes_tooltip'))
            ->action(function (Action $action, array $data) {
                $this->addNote();
            });

        $tools[] = Action::make('highlight')
            ->disabled(fn () => ! $this->rawMode || ($this->sme > 0 && $this->isLocked && ! $this->isOwner))
            ->icon('phosphor-highlighter-duotone')
            ->extraAttributes([
                'class' => 'w-32',
            ])
            ->button()
            ->outlined()
            ->color('warning')
            ->color(fn (): string => $this->highlightEnabled ? 'warning' : 'gray')
            ->label(__('vault.file_highlight_label'))
            ->tooltip(__('vault.file_highlight_tooltip'))
            ->action(function (Action $action, array $data) {
                $this->toggleHighlight();
            });

        // Share — owner-only, visible only when not yet shared
        $tools[] = Action::make('share')
            ->visible(fn () => ($this->sme === 0 || $this->isOwner) && ! $this->isShared)
            ->icon('phosphor-share-fat-duotone')
            ->extraAttributes([
                'class' => 'w-32',
            ])
            ->button()
            ->outlined()
            ->color('warning')
            ->label(__('vault.file_share_label'))
            ->tooltip(__('vault.file_share_tooltip'))
            ->modalWidth('4xl')
            ->modalIcon('phosphor-share-fat-duotone')
            ->modalSubmitActionLabel(__('vault.file_share_submit'))
            ->modalHeading(__('vault.file_share_heading'))
            ->modalDescription(__('vault.file_share_description'))
            ->modalContent(fn () => $this->getModalContent($this->shareUrl))
            ->action(fn () => $this->shareFile());

        // Uri link — owner-only, visible when already shared
        $tools[] = Action::make('urilink')
            ->visible(fn () => ($this->sme === 0 || $this->isOwner) && $this->isShared)
            ->icon('phosphor-link-simple-duotone')
            ->extraAttributes([
                'class' => 'w-32',
            ])
            ->button()
            ->outlined()
            ->color('warning')
            ->label(__('vault.file_urilink_label'))
            ->tooltip(__('vault.file_urilink_tooltip'))
            ->modalWidth('4xl')
            ->modalIcon('phosphor-link-simple-duotone')
            ->modalSubmitActionLabel(__('vault.file_urilink_submit'))
            ->modalHeading(__('vault.file_urilink_heading'))
            ->modalDescription(__('vault.file_urilink_description'))
            ->modalContent(fn () => $this->getModalContent($this->shareUrl))
            ->action(fn () => $this->copyUrl());

        // Unshare — owner-only, visible when already shared
        $tools[] = Action::make('unshare')
            ->visible(fn () => ($this->sme === 0 || $this->isOwner) && $this->isShared)
            ->icon('phosphor-share-fat-duotone')
            ->extraAttributes([
                'class' => 'w-32',
            ])
            ->button()
            ->outlined()
            ->color('danger')
            ->label(__('vault.file_unshare_label'))
            ->tooltip(__('vault.file_unshare_tooltip'))
            ->action(fn () => $this->unshareFile());

        $tools[] = Action::make('download')
            ->icon('phosphor-download-duotone')
            ->extraAttributes([
                'class' => 'w-32',
            ])
            ->button()
            ->outlined()
            ->color('warning')
            ->label(__('vault.file_download_label'))
            ->tooltip(__('vault.file_download_tooltip'))
            ->action(function ($action) {
                return $this->downloadFile();
                $action->cancel();
            });

        $tools[] = Action::make('lock')
            ->visible(fn () => $this->sme === 0 || $this->isOwner)
            ->extraAttributes([
                'class' => 'w-32',
            ])
            ->button()
            ->outlined()
            ->disabled(fn () => ! $this->isShared)
            ->icon(fn (): string => ! $this->isLocked ? 'phosphor-lock-key-open-duotone' : 'phosphor-lock-key-duotone')
            ->color(fn (): string => ! $this->isShared ? 'gray' : (! $this->isLocked ? 'warning' : 'danger'))
            ->label(fn (): string => ! $this->isLocked ? __('vault.file_lock_label') : __('vault.file_unlock_label'))
            ->tooltip(fn (): string => ! $this->isLocked ? __('vault.file_lock_tooltip') : __('vault.file_unlock_tooltip'))
            ->action(function (Action $action, $record) {
                $this->toggleLock($record);
            });

        $tools[] = Action::make('raw')
            ->icon(fn () => $this->rawMode ? 'phosphor-table-duotone' : 'phosphor-file-code-duotone')
            ->extraAttributes([
                'class' => 'w-32',
            ])
            ->button()
            ->outlined()
            ->color(fn () => ! $this->isTable ? 'gray' : 'warning')
            ->label(fn () => $this->rawMode ? __('vault.file_table_label') : __('vault.file_raw_label'))
            ->tooltip(fn () => $this->rawMode ? __('vault.file_table_tooltip') : __('vault.file_raw_tooltip'))
            ->disabled(fn () => ! $this->isTable)
            ->action(function (Action $action, array $data) {
                $this->toggleRaw();
            });

        foreach (Tools::query()->where('name', 'fileCompare')->where('enabled', true)->get() as $tool) {
            if (! checkAccess(auth()->user(), 'Advanced Tools')) {
                continue;
            }
            if ($tool->url) {
                $tools[] = Action::make($tool->name)
                    ->extraAttributes([
                        'class' => 'w-40',
                    ])
                    ->action(function ($record) use ($tool) {
                        $tabName = "SOS {$tool->name} {$this->filename}";
                        $tabId = "C{$this->fid}";
                        $url = $tool->url;
                        $url = str_replace('[vid]', $this->vid, $url);
                        $url = str_replace('[did]', $this->did, $url);
                        $url = str_replace('[fid]', $this->fid, $url);
                        $url = str_replace('[caseid]', $this->caseid, $url);

                        $this->dispatch('checkTab', ['url' => $url, 'tabName' => $tabName, 'tabId' => $tabId]);
                    })
                    ->openUrlInNewTab()
                    ->button()
                    ->outlined()
                    ->color('warning')
                    ->label(__('vault.file_compare_label'))
                    ->icon($tool->icon)
                    ->disabled(! $tool->enabled)
                    ->tooltip($tool->enabled ? $tool->tooltip : __('vault.file_upgrade_required'));
            }
        }

        return $tools;

        return $tools;
    }

    public function getFileInfo(): Schema
    {
        $schema = new Schema;

        return $schema
            ->extraAttributes([
                'class' => 'w-9/12',
            ])
            ->components([
                Grid::make(10)
                    ->schema([
                        TextEntry::make('name')
                            ->formatStateUsing(fn ($record) => ("/{$record->path}{$record->name}"))
                            ->extraAttributes([
                                'class' => 'text-nowrap',
                            ])
                            ->columnSpan(4)
                            ->size(TextSize::Medium)
                            ->color('warning')
                            ->label(__('vault.file_name_label')),
                        TextEntry::make('name')
                            ->formatStateUsing(function ($record): string {
                                $temp = explode('_', $record->name);
                                $small = array_slice($temp, 0, 2);

                                return implode(' ', $small).' ...';
                            })
                            ->limit(12)
                            ->tooltip(fn ($record) => str_replace('_', ' ', $record->name))
                            ->copyable()
                            ->copyableState(fn ($state) => str_replace('_', ' ', $state))
                            ->copyMessage('command copied')
                            ->copyMessageDuration(1500)
                            ->extraAttributes([
                                'class' => 'text-nowrap',
                            ])
                            ->size(TextSize::Medium)
                            ->color('warning')
                            ->label(__('vault.file_command_label')),
                        TextEntry::make('name')
                            ->formatStateUsing(fn ($record) => basename($record->path))
                            ->extraAttributes([
                                'class' => 'text-nowrap',
                            ])
                            ->size(TextSize::Medium)
                            ->color('warning')
                            ->label(__('vault.file_sos_plugin_label')),
                        TextEntry::make('size')
                            ->formatStateUsing(fn ($record) => Number::fileSize($record->size))
                            ->color('warning')
                            ->size(TextSize::Small)
                            ->label(__('vault.file_size_label')),
                        TextEntry::make('totalLines')
                            ->formatStateUsing(function ($record): string {
                                if (session('chunked')) {
                                    $text = Number::format(session('lines'));
                                    $text .= ' / ';
                                    $text .= Number::format($record->totalLines);
                                } else {
                                    $text = Number::format($record->totalLines);
                                }

                                return $text;
                            })
                            ->color('warning')
                            ->size(TextSize::Small)
                            ->label(__('vault.file_lines_count_label')),
                        TextEntry::make('date')
                            ->color('warning')
                            ->size(TextSize::Small)
                            ->date('d/M/Y')
                            ->label(__('vault.file_date_label')),
                        TextEntry::make('time')
                            ->color('warning')
                            ->size(TextSize::Small)
                            ->label(__('vault.file_time_label')),
                    ]),
            ]);

    }

    public function getCaseInfo($cid): Schema
    {
        $schema = new Schema;

        return $schema
            ->record(SupportCase::where('id', $cid)->first())
            ->components([
                Grid::make(10)
                    ->schema([
                        TextEntry::make('case')
                            ->columnSpan(2)
                            ->size(TextSize::Small)
                            ->color($this->color)
                            ->label(__('vault.file_case_label')),
                        TextEntry::make('serial')
                            ->size(TextSize::Small)
                            ->label(__('vault.file_serial_label')),
                        TextEntry::make('customer')
                            ->size(TextSize::Small)
                            ->label(__('vault.file_customer_label')),
                        TextEntry::make('host')
                            ->size(TextSize::Small)
                            ->label(__('vault.file_host_label')),
                        TextEntry::make('label')
                            ->size(TextSize::Small)
                            ->label(__('vault.file_label_label')),
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
                                default => 'gray',
                            })
                            ->size(TextSize::Small)
                            ->badge(),
                        TextEntry::make('created_at')
                            ->size(TextSize::Small)
                            ->date('d/M/Y')
                            ->label(__('vault.file_report_date_label')),
                        TextEntry::make('os_version')
                            ->columnSpan(2)
                            ->icon(fn ($record): string => $record->os_icon)
                            ->label(__('vault.file_os_label'))
                            ->limit(12)
                            ->tooltip(fn ($state): string => $state)
                            ->copyable()
                            ->copyMessage('OS version copied')
                            ->copyMessageDuration(1500)
                            ->formatStateUsing(function ($state): string {
                                $temp = explode(' ', $state);
                                $small = array_slice($temp, 0, 2);

                                return implode(' ', $small).' ...';
                            })
                            ->size(TextSize::Small)
                            ->iconColor('info'),
                    ]),
            ]);
    }

    public function mkSection($parent): array
    {

        $section = [];
        switch ($parent) {
            case 'fileCompare':
                $section = [
                    Grid::make(7) // !important
                        ->schema([
                            TextInput::make('')
                                ->columnSpan(4)
                                ->view('filament.components.infile-search'),
                            Select::make('caseid2')
                                ->columnSpan(2)
                                ->extraAttributes([
                                    'class' => 'mb-3 ring-4 ring-primary-600 dark:ring-primary-400 rounded-lg',
                                ])
                                ->hiddenLabel()
                                ->placeholder(__('vault.file_select_case'))
                                ->searchable(false)
                                ->native(true)
                                ->live()
                                ->afterStateUpdated(function ($state, $livewire) {
                                    $this->dispatch('toggle-comparing-on');
                                    $url = "/sosTool/{$this->vid}/{$this->did}/FileCompare/{$this->caseid}/{$this->fid}?cid2={$state}";
                                    return redirect()->to($url);
                                })
                                ->options($this->caseOptions)
                                ->getOptionLabelUsing(fn ($value): ?string => $this->caseOptions[$value] ?? null)
                                ->allowHtml(),
                        ]),
                ];
                break;
            case 'sosViewer':
                $section = [
                    Grid::make(7) // !important
                        ->extraAttributes(['class' => 'items-center'])
                        ->schema([
                            TextInput::make('')
                                ->columnSpan(4)
                                ->view('filament.components.infile-search'),
                            SchemaView::make('filament.components.status-badges')
                                ->extraAttributes([
                                    'class' => 'flex-row justify-start items-center flex-1 flex-wrap gap-2 w-full full min-w-[25vw]',
                                ])
                                ->columnSpan(3),

                        ]),
                ];
                break;
        }

        return $section;
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->record(FileContent::withParameters([
                'vid' => $this->vid,
                'did' => $this->did,
                'fid' => $this->fid,
                'cid' => $this->caseid,
                'format' => 'raw',
                'source' => 'file-controls-schema',
            ])
                ->where('case_id', $this->caseid)->first())
            ->components([
                Fieldset::make('')
                    ->contained(false)
                    ->extraAttributes([
                        'class' => 'my-0 py-0',
                    ])
                    ->columns(1)
                    ->schema([
                        Section::make('')
                            ->id('file-controls-section')
                            ->contained(false)
                            ->extraAttributes([
                                'x-data' => '{}',
                                'x-init' => "
                                    const patch = document.getElementById('bgpatch');
                                    if (!patch) return;

                                    const wrapper = document.getElementById('file-controls-section');
                                    const section = wrapper?.getElementsByTagName('section')[0];
                                    if (!section) return;

                                   const sync = () => {
                                        const collapsed = section.classList.contains('fi-collapsed');
                                        patch.classList.toggle('h-32', collapsed);
                                        patch.classList.toggle('h-64', !collapsed);
                                    };

                                    sync();

                                    const observer = new MutationObserver(sync);
                                    observer.observe(section, {
                                        attributes: true,
                                        attributeFilter: ['class'],
                                    });

                                    \$el.addEventListener('alpine:destroy', () => observer.disconnect());
                                ",
                                'class' => 'text-2xl my-1 py-1',
                            ])
                            ->heading($this->title)
                            ->icon($this->icon)
                            ->iconSize(IconSize::Large)
                            ->iconColor($this->color)
                            ->compact(true)
                            ->collapsible()
                            ->schema([
                                Action::make('Info')
                                    ->extraAttributes([
                                        'class' => 'absolute top-10 right-15',
                                    ])
                                    ->disabled(! $this->isTable)
                                    ->hidden(function () {
                                        return ($this->parent == 'fileCompare' || ! $this->isTable);
                                    })
                                    ->tooltip(function () {
                                        return $this->isTable ? __('vault.file_info_tooltip') : '';
                                    })
                                    ->requiresConfirmation()
                                    ->modalHeading(__('vault.file_info_heading'))
                                    ->modalDescription(function (): Htmlable {
                                        return new HtmlString($this->info);
                                    })
                                    ->modalCancelActionLabel(__('vault.file_info_ok'))
                                    ->modalIcon('phosphor-info-duotone')
                                    ->modalIconColor($this->color)
                                    ->modalWidth(Width::Medium)
                                    ->modalSubmitAction(false)
                                    ->modalAlignment(Alignment::Start)
                                    ->icon('phosphor-info-duotone')
                                    ->iconSize(IconSize::Large)
                                    ->color(function () {
                                        return $this->isTable ? $this->color : 'gray';
                                    })
                                    ->iconButton(),
                                Tabs::make('Tabs')
                                    ->contained(false)
                                    ->persistTab()
                                    ->extraAttributes([
                                        'class' => 'my-0 py-0',
                                    ])
                                    ->tabs([
                                        Tab::make(__('vault.file_file_info_tab'))
                                            ->label(__('vault.file_file_info_tab'))
                                            ->extraAttributes([
                                                'class' => 'my-2 py-0',
                                            ])
                                            ->icon('phosphor-file-text-duotone')
                                            ->schema([
                                                Flex::make(fn () => $this->getFileInfo())
                                                    ->grow(true),
                                            ]),
                                        Tab::make(__('vault.file_case_info_tab'))
                                            ->label(fn ($livewire): string => ! empty($livewire->caseid2) ? __('vault.file_case_left_info_tab') : __('vault.file_case_info_tab'))
                                            ->extraAttributes([
                                                'class' => 'my-2 py-0',
                                            ])
                                            ->icon('phosphor-ticket-duotone')
                                            ->schema([
                                                Flex::make(fn ($record) => $this->getCaseInfo($this->caseid))
                                                    ->grow(true),
                                            ]),
                                        Tab::make(__('vault.file_tab_case_right_info'))
                                            ->extraAttributes([
                                                'class' => 'my-2 py-0',
                                            ])
                                            ->visible(fn ($livewire): bool => ! empty($livewire->caseid2))
                                            ->icon('phosphor-ticket-duotone')
                                            ->schema([
                                                Flex::make(fn ($livewire) => $this->getCaseinfo($livewire->caseid2))
                                                    ->grow(true),
                                            ]),
                                        Tab::make(__('vault.file_tab_file_tools'))
                                            ->icon('phosphor-pencil-ruler-duotone')
                                            ->hidden(fn () => $this->parent == 'fileCompare')
                                            ->schema([
                                                Flex::make($this->getFileTools())
                                                    ->grow(true),
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
               $wire.on('toggle-line-numbers', window.sosViewer.toggleLineNumbers);
               $wire.on('add-note', window.sosViewer.addNote);
               $wire.on('toggle-highlight', window.sosViewer.toggleHighlight);
               $wire.on('toggle-raw', window.sosViewer.toggleRaw);
               $wire.on('copy-to-clipboard', (event) => {
                   const url = event[0].url;
                   if (url && navigator.clipboard) {
                       navigator.clipboard.writeText(url);
                   }
               });
               $wire.on('checkTab', (event) => {
                   const { url, tabName, tabId } = event[0];
                   const mesg = new FilamentNotification()
                   let newWindow;
                   if(!window.sosViewer.checkTab(tabId)) {
                       mesg.title('{{ __('vault.tool_tab_opening', ['name' => '']) }}' + tabName.replace(/SOS /,''))
                       mesg.icon('phosphor-bell-ringing-duotone')
                       mesg.iconColor('success')
                       newWindow = window.open(url, tabName);
                   } else {
                       mesg.title('{{ __('vault.file_compare_already_open') }}')
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

            <div class="fixed top-0 right-0 z-20 h-12 w-full bg-transparent pl-8">
                <div class="block z-20 h-4 w-full bg-zinc-50 dark:bg-zinc-800 "></div>
                <div id="bgpatch" class="block z-20 h-64 w-full bg-white dark:bg-zinc-800 border-t-1 border-zinc-200 dark:border-zinc-700 "> </div>
            </div>

            <header id="file-controls-content" wire:ignore.self class="fixed top-6 z-20 flex overflow-x-auto border rounded-lg px-4 pb-2 mr-5 bg-white dark:bg-zinc-900" >

                {{ $this->schema }}

            </header>

        @endif

</div>


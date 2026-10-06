<?php

namespace App\Livewire;

use App\Models\Annotation;
use App\Models\Bookmark;
use App\Models\ContentsRequest;
use App\Models\FileList;
use App\Models\Report;
use App\Models\SupportCase;
use App\Models\Sysevent;
use App\Models\VaultContent;
use App\Providers\VaultTools;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Livewire\Attributes\On;
use Livewire\Component;

class ListDirectories extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions, InteractsWithForms, InteractsWithTable;

    public ?array $data = [];

    public $vid;

    private $production = '';

    private $DEBUG = false;

    private $vtools;

    public function mount($vid)
    {
        $this->vid = $vid;
    }

    public function render()
    {
        return view('livewire.list-directories');
    }

    #[On('refreshComponents')]
    public function refreshDirectories() {}

    public function vtools(): ?VaultTools
    {
        if (isset($this->vtools)) {
            return $this->vtools;
        }

        if (! isset($this->vid)) {
            $message = __('vault.dir_no_vault');
            notifyError($message);

            return null;
        }

        $this->vtools = new VaultTools(auth()->user(), $this->vid);

        if (! isset($this->vtools)) {
            $message = __('vault.dir_vault_access_error');
            notifyError($message);

            return null;
        }

        if ($this->vtools->getVaultId() != $this->vid) {
            $message = __('vault.dir_wrong_vault');
            notifyError($message);

            return null;
        }

        if (! $this->vtools->isOpen()) {
            $message = __('vault.dir_vault_closed');
            notifyError($message);

            return null;
        }

        return $this->vtools;
    }

    public function confirmRepackDeleteAction(): Action
    {
        return Action::make('confirmRepackDelete')
            ->modalHeading(fn (array $arguments) => __('vault.repack_confirm_heading', ['name' => $arguments['name'] ?? '']))
            ->modalDescription(__('vault.repack_confirm_description'))
            ->modalSubmitActionLabel(__('vault.repack_modal_submit'))
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                $record = VaultContent::withParameters(['vid' => $this->vid])
                    ->newQuery()
                    ->where('type', 'd')
                    ->find($arguments['record_id'] ?? null);

                if (! $record) {
                    notifyError(__('vault.dir_not_found'));

                    return;
                }

                $this->doRepack($record, $arguments['passphrase'] ?? null, true);
            });
    }

    private function doRepack($record, ?string $passphrase, bool $deleteDir): void
    {
        $user = auth()->user();

        if (! isset($record) || empty($record)) {
            notifyError(__('vault.dir_no_case'));

            return;
        }

        if ($record->type !== 'd') {
            notifyError(__('vault.dir_wrong_type'));

            return;
        }

        if ((int) $record->vault_id !== (int) $this->vid) {
            Log::warning("Unauthorized repack attempt: user {$user->id} tried to repack dir {$record->id} from vault {$record->vault_id} via vid {$this->vid}");
            notifyError(__('vault.dir_not_found'));

            return;
        }

        $vtools = $this->vtools();
        if (! $vtools) {
            return;
        }

        $compression = $record->case?->compression ?: 'xz';
        $result = $vtools->repack($record->name, $passphrase, $compression);

        if (! $result['ok']) {
            notifyError($result['message'] ?: __('vault.repack_failed'));

            return;
        }

        $uid = $user->id;
        $gid = $user->group_id ?? $user->id;
        $cid = $record->case?->id ?? 0;

        $payload = (object) [
            'message' => $result['message'],
            'source_dir' => $record->name,
            'target_file' => $result['file'],
            'encrypted' => ($passphrase !== null && $passphrase !== ''),
            'compression' => $compression,
            'case_id' => $cid ?: null,
        ];

        addEvent($payload, 'REPACK', 'SUCCESS', 'ACTIVITY', $cid, $this->vid, $uid, $gid);

        $vtools->updateContents();

        Notification::make()
            ->title(__('vault.repack_success', ['file' => $result['file']]))
            ->icon('phosphor-bell-ringing-duotone')
            ->iconColor('success')
            ->send();

        // Drain pending notifications from the session BEFORE dispatching
        // refreshComponents. Without this, Filament's dehydrate hook fires
        // `notificationsSent` on every re-rendering sibling (parent volt,
        // ListDirectories, VaultBadge) in the same response cycle, the
        // Notifications widget receives concurrent updates with stale
        // snapshots, and the toast renders empty in production. Same
        // race condition the upload flow fixed in 02e5871.
        $pendingNotifications = session()->pull('filament.notifications', []);

        $this->dispatch('refreshComponents');

        // Deliver each drained toast purely client-side. Re-dispatching the
        // `notificationSent` Livewire event here instead races with the
        // refreshComponents fanout above and corrupts the shared notifications
        // component's snapshot (see filamentToastJs + bootstrap/app.php guard).
        foreach ($pendingNotifications as $notification) {
            if (is_array($notification)) {
                $this->js(filamentToastJs($notification));
            }
        }

        if ($deleteDir) {
            $this->delete($record);
        }
    }

    public function delete($record)
    {
        // this function deletes directories from the vault directly.
        // when deleteing a directory, the associated suport_case record shall also be deleted,
        // all associated annotations and contents_requests shall be deleted as well.

        $user = auth()->user();

        if (! isset($record) || empty($record)) {
            $message = __('vault.dir_no_case');
            notifyError($message);

            return;
        }

        $path = $this->vtools()->getMountPoint().'/';

        $this->DEBUG && Log::info("deleting directory: {$record->name}");

        $vid = $this->vid;
        $cid = null;
        $uid = $user->id;
        $gid = $user->group_id ?? $user->id;

        if ($record->type !== 'd') {
            $message = __('vault.dir_wrong_type');
            notifyError($message);

            return;
        }

        // Ensure the directory belongs to the current vault
        if ((int) $record->vault_id !== (int) $this->vid) {
            Log::warning("Unauthorized delete attempt: user {$uid} tried to delete dir {$record->id} from vault {$record->vault_id} via vid {$this->vid}");
            notifyError(__('vault.dir_not_found'));

            return;
        }

        if (! is_dir("{$path}{$record->name}")) {
            $message = __('vault.dir_not_found');
            notifyError($message);

            return;
        }

        $cmd = '/bin/sudo /bin/rm -rf '.escapeshellarg($path.basename($record->name));
        exec($cmd, $out, $ret);
        if ($ret) {
            $message = __('vault.dir_delete_error');
            Log::error($message);
            Log::error("$cmd: ".var_export($out, 1));
        }

        $this->vtools()->updateContents();

        // remove associated Annotations...
        $annotationsCount = Annotation::where('dir_id', $record->id)
            ->where('vault_id', $this->vid)
            ->where('group', $gid)
            ->count();
        Annotation::where('dir_id', $record->id)
            ->where('vault_id', $this->vid)
            ->where('group', $gid)
            ->delete();

        $logmessage = $annotationsCount.' associated annotations removed.';
        $this->DEBUG && Log::info($logmessage);

        // remove associated ContentRequests...
        $requestsCount = ContentsRequest::where('dir_id', $record->id)
            ->where('vault_id', $this->vid)
            ->where('group', $gid)
            ->count();
        ContentsRequest::where('dir_id', $record->id)
            ->where('vault_id', $this->vid)
            ->where('group', $gid)
            ->delete();

        $logmessage = $requestsCount.' associated content requests removed.';
        $this->DEBUG && Log::info($logmessage);

        // remove associated Bookmarks...
        $bookmarksCount = Bookmark::where('dir_id', $record->id)
            ->where('vault_id', $this->vid)
            ->where('user_id', $uid)
            ->count();
        Bookmark::where('dir_id', $record->id)
            ->where('vault_id', $this->vid)
            ->where('user_id', $uid)
            ->delete();

        $logmessage = $bookmarksCount.' associated bookmarks removed.';
        $this->DEBUG && Log::info($logmessage);

        // remove associated FileLists...
        $filelistsCount = FileList::where('dir_id', $record->id)
            ->where('vault_id', $this->vid)
            ->where('user_id', $uid)
            ->count();
        FileList::where('dir_id', $record->id)
            ->where('vault_id', $this->vid)
            ->where('user_id', $uid)
            ->delete();

        $logmessage = $filelistsCount.' associated file lists removed.';
        $this->DEBUG && Log::info($logmessage);

        // remove associated Reports...
        $reportsCount = Report::where('dir_id', $record->id)
            ->where('vault_id', $this->vid)
            ->count();
        Report::where('dir_id', $record->id)
            ->where('vault_id', $this->vid)
            ->delete();

        $logmessage = $reportsCount.' associated reports removed.';
        $this->DEBUG && Log::info($logmessage);

        // mark associated sysevents records for deletion in 30 days from now
        $sysevents = Sysevent::where('dir_id', $record->id)
            ->where('vault_id', $this->vid)
            ->where('group', $gid)
            ->update(['status' => 'DELETED']);

        $logmessage = "{$sysevents} associated sysevents marked for removal on 30 days from now.";
        $this->DEBUG && Log::info($logmessage);

        $supportCase = SupportCase::where('file_id', $record->id)
            ->where('vault_id', $this->vid)
            ->where('path', "{$path}{$record->name}")
            ->where('fstatus', 'AVAILABLE')
            ->first();

        $message = __('vault.dir_delete_success');

        $payload = (object) [
            'message' => $message,
            'name' => $record->name,
            'path' => $record->path,
            'id' => $record->id,
        ];

        if ($record->case) {
            $cid = $record->case->id;
            $payload = (object) [
                'message' => $message,
                'id' => $record->case->id,
                'secured' => $record->case->secured,
                'gpg' => $record->case->gpg,
                'tar' => $record->case->tar,
                'obfuscated' => $record->case->obfuscated,
                'path' => $record->case->path,
                'sosreport' => $record->case->sosreport,
                'label' => $record->case->label,
                'host' => $record->case->host,
                'case' => $record->case->case,
                'date' => $record->case->date,
                'sosid' => $record->case->sosid,
                'compression' => $record->case->compression,
                'customer' => $record->case->customer,
                'version' => $record->case->version,
                'link' => $record->case->link,
                'serial' => $record->case->serial,
            ];

            addEvent($payload, 'DEL_DIR', 'SUCCESS', 'ACTIVITY', $cid, $this->vid, $uid, $gid);

            $logmessage = "associated case {$record->case->case} deleted.";

            $record->case->delete();

            $this->DEBUG && Log::info($logmessage);

        }

        $record->delete();

        $this->DEBUG && Log::info($message);
        Notification::make()
            ->title($message)
            ->icon('phosphor-bell-ringing-duotone')
            ->iconColor('success')
            ->send();

        // Drain the toast from the session BEFORE dispatching refreshComponents
        // so the dehydrate hook doesn't fire `notificationsSent` across multiple
        // re-rendering siblings (would race and render the toast empty in prod).
        // Re-dispatching directly also gives VaultBadge a chance to reload its
        // stats so the dashboard cards reflect the new disk usage.
        $pendingNotifications = session()->pull('filament.notifications', []);

        $this->dispatch('refreshComponents');

        // Deliver each drained toast purely client-side. Re-dispatching the
        // `notificationSent` Livewire event here instead races with the
        // refreshComponents fanout above and corrupts the shared notifications
        // component's snapshot (see filamentToastJs + bootstrap/app.php guard).
        foreach ($pendingNotifications as $notification) {
            if (is_array($notification)) {
                $this->js(filamentToastJs($notification));
            }
        }
    }

    public function table(Table $table): Table
    {
        $deleteMessage = __('vault.dir_delete_modal_description');

        return $table
            ->query(VaultContent::withParameters([
                'vid' => $this->vid,
            ])->newQuery()
                ->where('type', 'd')
            )
            ->columns([
                TextColumn::make('name')
                    ->label(__('vault.dir_col_name'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('size')
                    ->formatStateUsing(fn ($record) => Number::fileSize(intval($record->size)))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('date')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('time')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name', 'desc')
            ->emptyStateHeading(__('vault.dir_empty_heading'))
            ->emptyStateDescription(__('vault.dir_empty_description'))
            ->emptyStateIcon('phosphor-empty-duotone')
            ->striped(true)
            ->deferColumnManager(false)
            ->reorderableColumns()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->paginated(true)
            ->recordActions([
                ViewAction::make()
                    ->icon(fn ($record): string => $record['os_icon'])
                    ->color(fn ($record): string => match ($record->case?->status) {
                        'OPEN' => 'primary',
                        'WAITCUST' => 'info',
                        'CLOSED' => 'danger',
                        'REOPEN' => 'primary',
                        'BLOCKED' => 'warning',
                        'SOLVED' => 'danger',
                        'DONE' => 'gray',
                        'WAIT' => 'info',
                        null => 'gray',
                        default => 'primary',
                    })
                    ->label(fn ($record): string => isset($record->case) ? $record->case->case : '')
                    ->modalWidth('6xl')
                    ->modalHeading(__('vault.dir_modal_heading'))
                    ->button()
                    ->outlined()
                    ->extraAttributes([
                        'class' => 'w-40',
                    ])
                    ->tooltip(__('vault.dir_view_tooltip'))
                    ->schema([
                        Fieldset::make('Label')
                            ->columns(1)
                            ->label(__('vault.dir_fieldset_case_data'))
                            ->schema([
                                Grid::make(6)
                                    ->schema([
                                        TextEntry::make('case.case')
                                            ->size(TextSize::Medium)
                                            ->color('primary')
                                            ->label(__('vault.dir_entry_case')),
                                        TextEntry::make('case.serial')
                                            ->size(TextSize::Medium)
                                            ->label(__('vault.dir_entry_serial')),
                                        TextEntry::make('case.status')
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
                                            ->size(TextSize::Medium)
                                            ->badge(),
                                        TextEntry::make('case.date')
                                            ->size(TextSize::Medium)
                                            ->date('d/M/Y')
                                            ->label(__('vault.dir_entry_report_date')),
                                        TextEntry::make('case.created_at')
                                            ->size(TextSize::Medium)
                                            ->date('d/M/Y')
                                            ->label(__('vault.dir_entry_created')),
                                        TextEntry::make('case.updated_at')
                                            ->size(TextSize::Medium)
                                            ->date('d/M/Y')
                                            ->label(__('vault.dir_entry_updated')),
                                    ]),
                            ]),
                        Fieldset::make('Label')
                            ->label(__('vault.dir_fieldset_report_data'))
                            ->columns(1)
                            ->schema([
                                Grid::make(5)
                                    ->schema([
                                        TextEntry::make('case.customer')
                                            ->size(TextSize::Medium)
                                            ->label(__('vault.dir_entry_customer')),
                                        TextEntry::make('case.host')
                                            ->size(TextSize::Medium)
                                            ->label(__('vault.dir_entry_host')),
                                        TextEntry::make('case.label')
                                            ->size(TextSize::Medium)
                                            ->label(__('vault.dir_entry_label')),
                                        TextEntry::make('case.version')
                                            ->size(TextSize::Medium)
                                            ->label(__('vault.dir_entry_version')),
                                        TextEntry::make('case.id')
                                            ->size(TextSize::Medium)
                                            ->label(__('vault.dir_entry_identifier'))
                                            ->numeric(),

                                        TextEntry::make('case.os_version')
                                            ->size(TextSize::Medium)
                                            ->columnspan(3)
                                            ->icon(fn ($record): string => $record['os_icon'])
                                            ->label(__('vault.dir_entry_os_version')),
                                        TextEntry::make('case.sos_version')
                                            ->size(TextSize::Medium)
                                            ->label(__('vault.dir_entry_sos_version')),

                                        TextEntry::make('case.path')
                                            ->formatStateUsing(fn ($record) => basename($record->case->path).'/')
                                            ->size(TextSize::Medium)
                                            ->columnspan(3)
                                            ->label(__('vault.dir_entry_folder')),
                                        TextEntry::make('case.link')
                                            ->size(TextSize::Medium)
                                            ->columnspan(2)
                                            ->label(__('vault.dir_entry_link')),
                                    ]),
                            ]),
                        Fieldset::make('Label')
                            ->label(__('vault.dir_fieldset_problem'))
                            ->columns(1)
                            ->schema([
                                Grid::make(1)
                                    ->schema([
                                        TextEntry::make('case.description')
                                            ->label(__('vault.dir_entry_description')),
                                        TextEntry::make('case.root_cause')
                                            ->label(__('vault.dir_entry_root_cause')),
                                        TextEntry::make('case.recommendation')
                                            ->label(__('vault.dir_entry_recommendation')),
                                    ]),
                            ]),
                    ]),
                ActionGroup::make([
                    Action::make('open')
                        ->label(__('vault.dir_action_browse'))
                        ->icon('phosphor-compass-duotone')
                        ->url(fn ($record): string => isset($record->case) ? '/sosbrowser/'.$record->case->id : ''),
                    Action::make('repack')
                        ->label(__('vault.repack_label'))
                        ->icon('phosphor-archive-duotone')
                        ->tooltip(__('vault.repack_tooltip'))
                        ->modalWidth('2xl')
                        ->modalHeading(fn ($record) => __('vault.repack_modal_heading', ['name' => $record->name]))
                        ->modalDescription(__('vault.repack_modal_description'))
                        ->modalSubmitActionLabel(__('vault.repack_modal_submit'))
                        ->modalIcon('phosphor-archive-duotone')
                        ->schema([
                            TextInput::make('passphrase')
                                ->label(__('vault.repack_passphrase_label'))
                                ->password()
                                ->revealable(),
                            Checkbox::make('delete_dir')
                                ->label(__('vault.repack_delete_dir'))
                                ->default(false),
                        ])
                        ->action(function (array $data, $record): void {
                            $passphrase = $data['passphrase'] ?? null;

                            if (! empty($data['delete_dir'])) {
                                $this->replaceMountedAction('confirmRepackDelete', [
                                    'record_id' => $record->id,
                                    'name' => $record->name,
                                    'passphrase' => $passphrase,
                                ]);

                                return;
                            }

                            $this->doRepack($record, $passphrase, false);
                        }),
                    DeleteAction::make()
                        ->requiresConfirmation()
                        ->modalHeading(fn ($record): string => __('vault.dir_delete_modal_heading', ['name' => $record->name]))
                        ->modalDescription($deleteMessage)
                        ->action(function ($record): void {
                            $this->delete($record);
                        })
                        ->mutateDataUsing(function (array $data): array {
                            $data['user_id'] = auth()->id();

                            return $data;
                        }),
                ]),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->requiresConfirmation()
                    ->modalDescription($deleteMessage)
                    // ->authorizeIndividualRecords('delete')
                    ->action(function (Collection $records): void {
                        foreach ($records as $record) {
                            $this->delete($record);
                        }
                    }),
            ]);
    }
}

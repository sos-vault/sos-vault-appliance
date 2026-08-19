<?php

use App\Models\Annotation;
use App\Models\ContentsRequest;
use App\Models\SupportCase;
use App\Models\Sysevent;
use Illuminate\Support\Facades\DB;
use App\Providers\VaultTools;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Livewire\Volt\Component;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;

middleware('auth');
name('cases');

new class extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms, InteractsWithTable;

    public ?array $data = [];

    private $vid;

    private $vtools;

    public string $tableTitle = '';

    public string $description = '';

    public function mount(): void
    {
        $this->tableTitle = __('cases.table_title');
        $this->description = __('cases.table_description');
    }

    public function delete($record)
    {
        // this function deletes a support case and also deletes the associated directory
        // when deleteing a directory, the associated suport_case record shall also be deleted,
        // all associated annotations and contents_requests shall be deleted as well.

        $user = auth()->user();

        if (! isset($record) || empty($record)) {
            $message = __('cases.error_no_case');
            notifyError($message);

            return;
        }

        // Public cases from another group cannot be deleted — hide them for this user instead.
        $gid = $user->group_id ?? $user->id;
        if ($record->is_public && (int) $record->group !== (int) $gid) {
            DB::table('user_hidden_cases')->insertOrIgnore([
                'user_id' => $user->id,
                'case_id' => $record->id,
            ]);
            Notification::make()
                ->title(__('cases.public_case_hidden'))
                ->icon('phosphor-eye-slash-duotone')
                ->iconColor('info')
                ->send();

            return;
        }

        $this->vid = $record->vault_id;

        if (! isset($this->vid)) {
            $message = __('cases.error_no_vault');
            notifyError($message);

            return;
        }

        $this->vtools = new VaultTools(auth()->user(), $this->vid);

        if (! isset($this->vtools)) {
            $message = __('cases.error_no_access');
            notifyError($message);

            return;
        }

        if ($this->vtools->getVaultId() != $this->vid) {
            $message = __('cases.error_wrong_vault');
            notifyError($message);

            return;
        }

        if (! $this->vtools->isOpen()) {
            $message = __('cases.error_vault_closed');
            notifyError($message);

            return;
        }

        $message = '';

        $path = $this->vtools->getMountPoint().'/';

        $dir = $this->vtools->getDirById($record->file_id);

        if (isset($dir) && is_dir("{$path}{$dir->name}")) {
            $message .= ' Associated Directory deleted.';
            $cmd = '/bin/sudo /bin/rm -rf '.escapeshellarg($path.basename($dir->name));
            exec($cmd, $out, $ret);
            if ($ret) {
                $message = __('cases.error_delete_dir');
                notifyError($message);
                Log::error("$cmd: ".var_export($out, 1));
            } else {
                $logmessage = "Directory {$path}{$dir->name} deleted.";
                Log::info($logmessage);
            }
        }

        $this->vtools->updateContents();

        $message .= ' Case record deleted.';

        $payload = (object) [
            'message' => $message,
            'id' => $record->id,
            'secured' => $record->secured,
            'gpg' => $record->gpg,
            'tar' => $record->tar,
            'obfuscated' => $record->obfuscated,
            'path' => $record->path,
            'sosreport' => $record->sosreport,
            'label' => $record->label,
            'host' => $record->host,
            'case' => $record->case,
            'date' => $record->date,
            'sosid' => $record->sosid,
            'compression' => $record->compression,
            'customer' => $record->customer,
            'version' => $record->version,
            'link' => $record->link,
            'serial' => $record->serial,
        ];

        $uid = $user->id;
        $gid = $user->group_id ?? $user->id;

        // remove associated Annotations...
        Annotation::where('dir_id', $record->file_id)
            ->where('vault_id', $this->vid)
            ->where('group', $gid)
            ->delete();

        Log::info('associated annotations removed.');

        // remove associated ContentRequests...
        ContentsRequest::where('dir_id', $record->file_id)
            ->where('vault_id', $this->vid)
            ->where('group', $gid)
            ->delete();

        Log::info('associated content requests removed.');

        // mark associated sysevents records for deletion in 30 days from now
        $sysevents = Sysevent::where('dir_id', $record->file_id)
            ->where('vault_id', $this->vid)
            ->where('group', $gid)
            ->update(['status' => 'DELETED']);

        $logmessage = "{$sysevents} associated sysevents marked for removal on 30 days from now.";
        Log::info($logmessage);

        addEvent($payload, 'DEL_CASE', 'SUCCESS', 'ACTIVITY', $record->id, $this->vid, $uid, $gid);

        $record->delete();

        Log::info($message);
        Notification::make()
            ->title($message)
            ->icon('phosphor-bell-ringing-duotone')
            ->iconColor('success')
            ->send();
    }

    public function table(Table $table): Table
    {
        $deleteMessage = __('cases.delete_confirm');

        $gid = auth()->user()->group_id ?? auth()->id();

        $uid = auth()->id();

        return $table
            ->query(SupportCase::query()->where(function ($q) use ($gid) {
                $q->where('group', $gid)->orWhere('is_public', true);
            })->whereNotIn('id', function ($q) use ($uid) {
                $q->select('case_id')->from('user_hidden_cases')->where('user_id', $uid);
            })->with('createdBy'))
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
            ->reorderable('sort')
            ->striped(true)
            ->deferColumnManager(false)
            ->reorderableColumns()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->paginated(true)
            ->recordActions([
                    ActionGroup::make([
                    ViewAction::make()
                        ->modalWidth('6xl')
                        ->label(__('cases.action_view'))
                        ->schema([
                            Fieldset::make('Label')
                                ->columns(1)
                                ->label(__('cases.section_case_data'))
                                ->schema([
                                    Grid::make(6)
                                        ->schema([
                                            TextEntry::make('case')
                                                ->size(TextSize::Medium)
                                                ->color('primary')
                                                ->label(__('cases.col_case')),
                                            TextEntry::make('serial')
                                                ->size(TextSize::Medium)
                                                ->label(__('cases.col_serial')),
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
                                                ->size(TextSize::Medium)
                                                ->badge(),
                                            TextEntry::make('date')
                                                ->size(TextSize::Medium)
                                                ->date('d/M/Y')
                                                ->label(__('cases.col_report_date')),
                                            TextEntry::make('created_at')
                                                ->size(TextSize::Medium)
                                                ->date('d/M/Y')
                                                ->label(__('cases.col_created')),
                                            TextEntry::make('updated_at')
                                                ->size(TextSize::Medium)
                                                ->date('d/M/Y')
                                                ->label(__('cases.col_updated')),
                                            TextEntry::make('createdBy.name')
                                                ->size(TextSize::Medium)
                                                ->label(__('cases.col_owner')),
                                        ]),
                                ]),
                            Fieldset::make('Label')
                                ->label(__('cases.section_report_data'))
                                ->columns(1)
                                ->schema([
                                        Grid::make(5)
                                            ->schema([
                                                TextEntry::make('customer')
                                                    ->size(TextSize::Medium)
                                                    ->label(__('cases.col_customer')),
                                                TextEntry::make('host')
                                                    ->size(TextSize::Medium)
                                                    ->label(__('cases.col_host')),
                                                TextEntry::make('label')
                                                    ->size(TextSize::Medium)
                                                    ->label(__('cases.col_label')),
                                                TextEntry::make('version')
                                                    ->size(TextSize::Medium)
                                                    ->label(__('cases.col_version')),
                                                TextEntry::make('id')
                                                    ->size(TextSize::Medium)
                                                    ->label(__('cases.col_identifier'))
                                                    ->numeric(),

                                                TextEntry::make('os_version')
                                                    ->size(TextSize::Medium)
                                                    ->columnspan(3)
                                                    ->icon(fn ($record): string => $record['os_icon'])
                                                    ->label(__('cases.col_os_version')),
                                                TextEntry::make('sos_version')
                                                    ->size(TextSize::Medium)
                                                    ->label(__('cases.col_sos_version')),

                                                TextEntry::make('path')
                                                    ->formatStateUsing(fn (SupportCase $record) => basename($record->path).'/')
                                                    ->size(TextSize::Medium)
                                                    ->columnspan(3)
                                                    ->label(__('cases.col_folder')),
                                                TextEntry::make('link')
                                                    ->size(TextSize::Medium)
                                                    ->columnspan(2)
                                                    ->label('Link'),
                                                TextEntry::make('sha256')
                                                    ->size(TextSize::Medium)
                                                    ->columnspan(3)
                                                    ->label("SHA256"),
                                            ]),
                                    ]),
                            Fieldset::make('Label')
                                ->label(__('cases.section_problem'))
                                ->columns(1)
                                ->schema([
                                        Grid::make(1)
                                            ->schema([
                                                TextEntry::make('description')
                                                ->label(__('cases.col_description')),
                                                TextEntry::make('root_cause')
                                                ->label(__('cases.col_root_cause')),
                                                TextEntry::make('recommendation')
                                                ->label(__('cases.col_recommendation')),
                                            ]),
                                    ]),
                        ]),
                    Action::make('open')
                        ->label(__('cases.action_browse'))
                        ->icon('phosphor-binoculars-duotone')
                        ->url(fn ($record): string => '/sosbrowser/'.$record->id),
                    EditAction::make()
                        ->visible(function (SupportCase $record): bool {
                            $gid = auth()->user()->group_id ?? auth()->id();

                            return ! ($record->is_public && (int) $record->group !== (int) $gid);
                        })
                        ->after(function (SupportCase $record): void {
                            $uid = auth()->id() ?? 0;
                            $gid = auth()->user()->group_id ?? $uid;
                            $payload = (object) ['message' => 'case updated', 'case' => $record->case];
                            addEvent($payload, 'CHG_CASE', 'SUCCESS', 'ACTIVITY', $record->id, $record->vault_id, $uid, $gid);
                        })
                        ->modalWidth('6xl')
                        ->mutateRecordDataUsing(function (array $data): array {
                            $data['created_at'] = Carbon::parse($data['created_at'])->format('d/M/Y');
                            $data['updated_at'] = Carbon::parse($data['updated_at'])->format('d/M/Y');
                            $data['date'] = Carbon::parse($data['date'])->format('d/M/Y');

                            return $data;
                        })
                        ->schema([
                            Fieldset::make('Label')
                                ->columns(1)
                                ->label(fn (SupportCase $record) => 'report '.basename($record->path))
                                ->schema([
                                    Grid::make([
                                        'default' => 4,
                                    ])
                                        ->schema([
                                            TextInput::make('id')
                                                ->numeric()
                                                ->extraAttributes(['class' => 'bg-transparent'])
                                                ->disabled()
                                                ->readonly(),
                                            TextInput::make('date')
                                                ->label(__('cases.col_report_date'))
                                                ->extraAttributes(['class' => 'bg-transparent'])
                                                ->disabled(),
                                            TextInput::make('created_at')
                                                ->label(__('cases.col_created'))
                                                ->extraAttributes(['class' => 'bg-transparent'])
                                                ->disabled(),
                                            TextInput::make('updated_at')
                                                ->label(__('cases.col_updated'))
                                                ->extraAttributes(['class' => 'bg-transparent'])
                                                ->disabled(),
                                        ]),
                                ]),
                            Grid::make([
                                'default' => 3,
                                'sm' => 1,
                                'md' => 2,
                                'lg' => 3,
                                'xl' => 4,
                                '2xl' => 5,
                            ])
                                ->schema([
                                    TextInput::make('case')
                                        ->label(__('cases.col_case'))
                                        ->required()
                                        ->live()
                                        ->alphaDash()
                                        ->maxLength(15)
                                        ->minLength(3),
                                    TextInput::make('customer')
                                        ->label(__('cases.col_customer'))
                                        ->required()
                                        ->live()
                                        ->notRegex('/[\$!@#%^*{}=<>+?[\]()|~`;,.\/\\\]+/')    // no strange charcaters complete
                                        ->maxLength(15)
                                        ->minLength(3),
                                    TextInput::make('host')
                                        ->label(__('cases.col_host'))
                                        ->required()
                                        ->live()
                                        ->regex('/[\d\w.\-]{3,24}/')    // only these charcaters
                                        ->notRegex('/[\$!@#%^*{}=<>+?[\]()|~`;,\/\\\]+/')    // no strange charcaters no dot
                                        ->maxLength(24)
                                        ->minLength(3),
                                    TextInput::make('label')
                                        ->label(__('cases.col_label'))
                                        ->required()
                                        ->live()
                                        ->nullable()
                                        ->notRegex('/[\$!@#%^*{}=<>+?[\]()|~`;,\/\\\]+/')    // no strange charcaters no dot
                                        ->maxLength(15)
                                        ->minLength(3),
                                    TextInput::make('serial')
                                        ->label(__('cases.col_serial'))
                                        ->live()
                                        ->nullable()
                                        ->numeric()
                                        ->maxLength(5)
                                        ->minLength(1),
                                    TextInput::make('version')
                                        ->label(__('cases.col_version'))
                                        ->live()
                                        ->nullable()
                                        ->regex('/[\d\w\s_.\-]{3,15}/')    // only these charcaters
                                        ->notRegex('/[\$!@#%^*{}=<>+?[\]()|~`;,\/\\\]+/')    // no strange charcaters no dot
                                        ->maxLength(15)
                                        ->minLength(3),
                                    Select::make('status')
                                        ->label(__('cases.col_status'))
                                        ->columnSpan(2)
                                        ->required()
                                        ->native(true)
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
                                    TextInput::make('link')
                                        ->label(__('cases.col_link'))
                                        ->live()
                                        ->url()
                                        ->nullable()
                                        ->maxLength(256)
                                        ->minLength(8)
                                        ->columnSpan([
                                            'default' => 2,
                                        ]),
                                ]),
                            Textarea::make('description')
                                ->label(__('cases.col_description'))
                                ->live()
                                ->nullable()
                                ->maxLength(2048)
                                ->minLength(8),
                            Textarea::make('root_cause')
                                ->label(__('cases.col_root_cause'))
                                ->live()
                                ->nullable()
                                ->maxLength(2048)
                                ->minLength(24),
                            Textarea::make('recommendation')
                                ->label(__('cases.col_recommendation'))
                                ->live()
                                ->nullable()
                                ->maxLength(2048)
                                ->minLength(24),
                        ]),
                    DeleteAction::make()
                        ->requiresConfirmation()
                        ->modalHeading(fn ($record): string => __('cases.delete_heading', ['case' => $record->case]))
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
?>

<x-layouts.app>
    @volt('cases')
        <x-app.container>

            <x-filament::section :description="$description" :heading="$tableTitle" :contained="false"
                icon="phosphor-ticket-duotone" icon-color="primary" icon-size="lg"
            >

                <div class="overflow-x-auto border rounded-lg">
                    {{ $this->table}}
                </div>

            </x-filament::section>

        </x-app.container>
    @endvolt
</x-layouts.app>

<div class="hidden
    text-info-800
    text-info-700
    text-info-600
    text-info-500
    text-info-400
    text-info-300
    text-info-200
    text-info-100

    text-primary-800
    text-primary-700
    text-primary-600
    text-primary-500
    text-primary-400
    text-primary-300
    text-primary-200
    text-primary-100

    text-danger-800
    text-danger-700
    text-danger-600
    text-danger-500
    text-danger-400
    text-danger-300
    text-danger-200
    text-danger-100

    text-warning-800
    text-warning-700
    text-warning-600
    text-warning-500
    text-warning-400
    text-warning-300
    text-warning-200
    text-warning-100

    border-info-800
    border-info-700
    border-info-600
    border-info-500
    border-info-400
    border-info-300
    border-info-200
    border-info-100

    border-primary-800
    border-primary-700
    border-primary-600
    border-primary-500
    border-primary-400
    border-primary-300
    border-primary-200
    border-primary-100

    border-danger-800
    border-danger-700
    border-danger-600
    border-danger-500
    border-danger-400
    border-danger-300
    border-danger-200
    border-danger-100

    border-warning-800
    border-warning-700
    border-warning-600
    border-warning-500
    border-warning-400
    border-warning-300
    border-warning-200
    border-warning-100

    bg-info-800
    bg-info-700
    bg-info-600
    bg-info-500
    bg-info-400
    bg-info-300
    bg-info-200
    bg-info-100

    bg-primary-800
    bg-primary-700
    bg-primary-600
    bg-primary-500
    bg-primary-400
    bg-primary-300
    bg-primary-200
    bg-primary-100

    bg-danger-800
    bg-danger-700
    bg-danger-600
    bg-danger-500
    bg-danger-400
    bg-danger-300
    bg-danger-200
    bg-danger-100

    bg-warning-800
    bg-warning-700
    bg-warning-600
    bg-warning-500
    bg-warning-400
    bg-warning-300
    bg-warning-200
    bg-warning-100
"></div>


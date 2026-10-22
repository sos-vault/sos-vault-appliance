<?php

namespace App\Livewire;

use App\Models\Alert;
use App\Models\SupportCase;
use App\Rules\SlackWebhookUrl;
use App\Rules\ValidPcreRegex;
use App\Services\SlackAlertService;
use App\Services\VaultAccess;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AlertsTable extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    #[Locked]
    public $vid;

    #[Locked]
    public $did;

    #[Locked]
    public $caseid;

    public ?string $prefillPath = null;

    public bool $autoCreate = false;

    public function mount($vid, $did = null, $caseid = null, ?string $prefillPath = null, bool $autoCreate = false): void
    {
        if (isSaas() && ! checkAccess(auth()->user(), 'Alert Rules')) {
            abort(403);
        }

        $this->vid = $vid;
        $this->did = $did;
        $this->caseid = $caseid;
        $this->prefillPath = $prefillPath;
        $this->autoCreate = $autoCreate;
    }

    public function render()
    {
        return view('livewire.alerts-table');
    }

    public function mountInteractsWithTable(): void
    {
        if ($this->autoCreate) {
            $this->mountAction('create');
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Alert::query()->where('vault_id', $this->vid))
            ->emptyStateHeading(__('alerts.table_empty_heading'))
            ->emptyStateDescription(__('alerts.table_empty_description'))
            ->emptyStateIcon('phosphor-bell-ringing-duotone')
            ->columns([
                TextColumn::make('name')->label(__('alerts.column_name'))->searchable()->sortable(),
                TextColumn::make('severity')
                    ->label(__('alerts.column_severity'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'INFO' => 'gray',
                        'WARNING' => 'warning',
                        'ERROR' => 'orange',
                        default => 'danger',
                    }),
                TextColumn::make('type')->label(__('alerts.column_type'))->badge(),
                ToggleColumn::make('enabled')->label(__('alerts.column_enabled')),
                TextColumn::make('user.name')->label(__('alerts.column_creator')),
                IconColumn::make('notify_enabled')->label('N')->boolean()->tooltip(__('alerts.column_delivery_notification')),
                IconColumn::make('email_enabled')->label('E')->boolean()->tooltip(__('alerts.column_delivery_email')),
                IconColumn::make('event_enabled')->label('V')->boolean()->tooltip(__('alerts.column_delivery_event')),
                IconColumn::make('contact_point_enabled')->label('C')->boolean()->tooltip(__('alerts.column_delivery_contact_point')),
                TextColumn::make('created_at')->label(__('alerts.column_created_at'))->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('alerts.action_create'))
                    ->visible(fn () => VaultAccess::canManage(auth()->user(), $this->vid))
                    ->schema(fn () => $this->formSchema())
                    ->mutateDataUsing(fn (array $data) => $this->mutateDataBeforeSave($data)),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->schema(fn () => $this->formSchema())
                        ->mutateRecordDataUsing(fn (array $data, Alert $record) => [...$data, 'contact_point_webhook_url' => $record->contact_point_webhook_url])
                        ->mutateDataUsing(fn (array $data) => $this->mutateDataBeforeSave($data)),
                    DeleteAction::make(),
                ])->visible(fn () => VaultAccess::canManage(auth()->user(), $this->vid)),
            ]);
    }

    private function mutateDataBeforeSave(array $data): array
    {
        $data['vault_id'] = $this->vid;
        $data['user_id'] ??= auth()->id();

        return $data;
    }

    /** @return array<int, \Filament\Schemas\Components\Component> */
    private function formSchema(): array
    {
        $referenceCaseOptions = SupportCase::where('vault_id', $this->vid)
            ->orderByDesc('id')
            ->pluck('label', 'id')
            ->toArray();

        return [
            TextInput::make('name')
                ->label(__('alerts.field_name'))
                ->placeholder(__('alerts.placeholder_name'))
                ->required()
                ->maxLength(100),
            Textarea::make('description')
                ->label(__('alerts.field_description'))
                ->placeholder(__('alerts.placeholder_description'))
                ->maxLength(1000),
            Select::make('severity')
                ->label(__('alerts.field_severity'))
                ->options(collect(Alert::SEVERITIES)->mapWithKeys(fn ($s) => [$s => __("alerts.severity_{$s}")]))
                ->default('WARNING')
                ->required(),

            Select::make('type')
                ->label(__('alerts.field_type'))
                ->options([
                    'grep' => __('alerts.type_grep'),
                    'levels' => __('alerts.type_levels'),
                    'diff' => __('alerts.type_diff'),
                ])
                ->required()
                ->live(),

            Section::make(__('alerts.type_grep'))
                ->visible(fn (Get $get) => $get('type') === 'grep')
                ->schema([
                    TextInput::make('grep_path')
                        ->label(__('alerts.field_grep_path'))
                        ->placeholder(__('alerts.placeholder_grep_path'))
                        ->default(fn () => $this->prefillPath)
                        ->required(fn (Get $get) => $get('type') === 'grep')
                        ->maxLength(1024)
                        ->rule('regex:/^(?!.*\.\.).*$/'),
                    TextInput::make('grep_regex')
                        ->label(__('alerts.field_grep_regex'))
                        ->placeholder(__('alerts.placeholder_grep_regex'))
                        ->required(fn (Get $get) => $get('type') === 'grep')
                        ->maxLength(500)
                        ->rules([new ValidPcreRegex]),
                    Radio::make('grep_match_mode')
                        ->label(__('alerts.field_grep_match_mode'))
                        ->options([
                            'found' => __('alerts.grep_match_found'),
                            'not_found' => __('alerts.grep_match_not_found'),
                        ])
                        ->default('found')
                        ->required(fn (Get $get) => $get('type') === 'grep'),
                ]),

            Section::make(__('alerts.type_levels'))
                ->visible(fn (Get $get) => $get('type') === 'levels')
                ->schema([
                    Select::make('levels_metric')
                        ->label(__('alerts.field_levels_metric'))
                        ->options(collect(Alert::LEVELS_METRICS)->mapWithKeys(fn ($m) => [$m => __("alerts.metric_{$m}")]))
                        ->required(fn (Get $get) => $get('type') === 'levels')
                        ->live(),
                    TextInput::make('levels_threshold')
                        ->label(__('alerts.field_levels_threshold'))
                        ->placeholder(__('alerts.placeholder_levels_threshold'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(fn (Get $get) => in_array($get('levels_metric'), ['cpu', 'disk', 'inodes', 'memory', 'swap'], true) ? 100 : 1000000)
                        ->required(fn (Get $get) => $get('type') === 'levels'),
                    TextInput::make('levels_mount_path')
                        ->label(__('alerts.field_levels_mount_path'))
                        ->placeholder(__('alerts.placeholder_levels_mount_path'))
                        ->maxLength(255)
                        ->visible(fn (Get $get) => in_array($get('levels_metric'), ['disk', 'inodes'], true))
                        ->required(fn (Get $get) => in_array($get('levels_metric'), ['disk', 'inodes'], true)),
                ]),

            Section::make(__('alerts.type_diff'))
                ->visible(fn (Get $get) => $get('type') === 'diff')
                ->schema([
                    TextInput::make('diff_path')
                        ->label(__('alerts.field_diff_path'))
                        ->placeholder(__('alerts.placeholder_diff_path'))
                        ->default(fn () => $this->prefillPath)
                        ->required(fn (Get $get) => $get('type') === 'diff')
                        ->maxLength(1024)
                        ->rule('regex:/^(?!.*\.\.).*$/'),
                    Select::make('diff_reference_case_id')
                        ->label(__('alerts.field_diff_reference_case'))
                        ->options($referenceCaseOptions)
                        ->searchable()
                        ->required(fn (Get $get) => $get('type') === 'diff')
                        ->disabled(fn (?Alert $record) => $record !== null)
                        ->rule('exists:support_cases,id'),
                ]),

            Section::make(__('alerts.section_delivery'))
                ->schema([
                    Toggle::make('notify_enabled')->label(__('alerts.field_delivery_notification'))->live(),

                    Toggle::make('email_enabled')->label(__('alerts.field_delivery_email'))->live(),
                    TextInput::make('email_addresses')
                        ->label(__('alerts.field_email_addresses'))
                        ->placeholder(__('alerts.placeholder_email_addresses'))
                        ->visible(fn (Get $get) => $get('email_enabled'))
                        ->required(fn (Get $get) => $get('email_enabled'))
                        ->rules([fn () => $this->emailListRule()]),

                    Toggle::make('event_enabled')->label(__('alerts.field_delivery_event')),

                    Toggle::make('contact_point_enabled')
                        ->label(__('alerts.field_delivery_contact_point'))
                        ->live()
                        ->disabled(fn () => ! app(SlackAlertService::class)->licensed())
                        ->helperText(fn () => app(SlackAlertService::class)->licensed() ? null : __('alerts.contact_point_license_required')),
                    Select::make('contact_point_destination')
                        ->label(__('alerts.field_contact_point_destination'))
                        ->options(collect(Alert::CONTACT_POINT_DESTINATIONS)->mapWithKeys(fn ($d) => [$d => __("alerts.destination_{$d}")]))
                        ->disableOptionWhen(fn ($value) => $value !== 'slack')
                        ->helperText(__('alerts.contact_point_coming_soon'))
                        ->default('slack')
                        ->live()
                        ->visible(fn (Get $get) => $get('contact_point_enabled'))
                        ->required(fn (Get $get) => $get('contact_point_enabled'))
                        ->rule('in:'.implode(',', Alert::CONTACT_POINT_DESTINATIONS)),
                    TextInput::make('contact_point_webhook_url')
                        ->label(__('alerts.field_contact_point_webhook_url'))
                        ->placeholder(__('alerts.placeholder_contact_point_webhook_url'))
                        ->password()
                        ->revealable()
                        ->visible(fn (Get $get) => $get('contact_point_enabled') && $get('contact_point_destination') === 'slack')
                        ->required(fn (Get $get) => $get('contact_point_enabled') && $get('contact_point_destination') === 'slack')
                        ->rules([new SlackWebhookUrl])
                        ->hintAction(
                            Action::make('testSlack')
                                ->label(__('alerts.contact_point_test'))
                                ->action(function (Get $get) {
                                    $ok = app(SlackAlertService::class)->test($get('contact_point_webhook_url') ?? '');

                                    Notification::make()
                                        ->title($ok ? __('alerts.contact_point_test_success') : __('alerts.contact_point_test_failure'))
                                        ->color($ok ? 'success' : 'danger')
                                        ->send();
                                })
                        ),
                ]),
        ];
    }

    private function emailListRule(): \Closure
    {
        return function (string $attribute, $value, \Closure $fail) {
            $addresses = collect(explode(',', (string) $value))->map(fn ($e) => trim($e))->filter();

            if ($addresses->count() > 10) {
                $fail(__('alerts.validation_email_too_many'));

                return;
            }

            foreach ($addresses as $address) {
                if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $fail(__('alerts.validation_email_invalid', ['address' => $address]));

                    return;
                }
            }
        };
    }
}

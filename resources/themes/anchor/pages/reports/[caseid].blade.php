<?php
    use App\Models\Report;
    use App\Models\SupportCase;
    use App\Models\ITSMProvider;
    use App\Services\JiraService;

    use Barryvdh\DomPDF\Facade\Pdf;
    use Illuminate\Support\Facades\Blade;

    use Filament\Forms\{Form, Concerns\InteractsWithForms, Contracts\HasForms};
    use Filament\Forms\Components\{Textarea, TextInput, DatePicker};
    use Filament\Notifications\Notification;

    use Filament\Tables;
    use Filament\Tables\Table;
    use Filament\Tables\Contracts\HasTable;
    use Filament\Tables\Concerns\InteractsWithTable;
    use Filament\Tables\Columns\TextColumn;
    use Filament\Tables\Columns\IconColumn;
    use Filament\Tables\Filters\SelectFilter;

    use Filament\Schemas\Components\Section;
    use Filament\Forms\Components\Select;
    use Filament\Forms\Components\TextInput\Mask;
    use Filament\Forms\Components\RichEditor;
    use Filament\Infolists\Components\TextEntry;
    use Filament\Support\Enums\TextSize;
    use Filament\Support\Enums\IconSize;

    use Filament\Actions\Contracts\HasActions;
    use Filament\Actions\Concerns\InteractsWithActions;
    use Filament\Actions\Action;
    use Filament\Actions\ViewAction;
    use Filament\Actions\ActionGroup;
    use Filament\Schemas\Components\Fieldset;
    use Filament\Schemas\Components\Grid;
    use Filament\Actions\EditAction;
    use Filament\Actions\DeleteAction;
    use Filament\Actions\BulkActionGroup;
    use Filament\Actions\DeleteBulkAction;
    use Filament\Actions\CreateAction;

    use Filament\Schemas\Components\Tabs;
    use Filament\Schemas\Components\Tabs\Tab;

    use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ActivityReportBlock;
    use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\AnnotationsBlock;
    use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\HostBlock;
    use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\CpuBlock;
    use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\MemBlock;
    use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\DiskBlock;
    use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\TcpSocketsBlock;
    use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\UnixSocketsBlock;
    use App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks\ProcBlock;
    use Filament\Forms\Components\RichEditor\RichContentRenderer;

    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\HtmlString;
    use Carbon\Carbon;

    use Livewire\Volt\Component;
    use function Laravel\Folio\{middleware, name};
    use function Livewire\Volt\{mount, state, computed};

    middleware('auth');
    name('reports');
    state(['caseid']);

    new class extends Component implements HasForms, HasTable, HasActions
    {
        use InteractsWithActions;
        use InteractsWithForms, InteractsWithTable;

        public ?array $data = [];
        public $caseid;
        public $case;
        public $vid;
        public $did;

        public $buttonId = "";

        public $tableTitle = '';
        public $tableDescr = '';

        public $isAlreadySaved = "false";

        // view constants
        public $heading = '';
        public $description = '';
        public $icon = "phosphor-list-checks-duotone";
        public $color = "primary";

        public $template;

        public $statusOptions = [
            'DRAFT'   => 'DRAFT',
            'POSTED'  => 'POSTED',
            'CLOSED'  => 'CLOSED',
            'REOPEN'  => 'REOPEN',
            'PENDING' => 'PENDING',
            'SOLVED'  => 'SOLVED',
            'DONE'    => 'DONE',
        ];

        public $statusColors = [
            'DRAFT'   => 'primary',
            'POSTED'  => 'info',
            'CLOSED'  => 'danger',
            'REOPEN'  => 'primary',
            'PENDING' => 'warning',
            'SOLVED'  => 'danger',
            'DONE'    => 'gray',
            'WAIT'    => 'info',
        ];

        public $typeOptions = [
            'INCIDENT'   => 'INCIDENT',
            'POSTMORTEM' => 'POSTMORTEM',
            'ISSUE'      => 'ISSUE',
            'REPORT'     => 'REPORT',
            'UPDATE'     => 'UPDATE',
            'FINAL'      => 'FINALdanger',
            'TEMPLATE'   => 'TEMPLATE',
        ];

        public $typeColors = [
            'INCIDENT'   => 'primary',
            'POSTMORTEM' => 'info',
            'ISSUE'      => 'danger',
            'REPORT'     => 'primary',
            'UPDATE'     => 'warning',
            'FINAL'      => 'danger',
            'TEMPLATE'   => 'gray',
        ];

        public $mergeTagsNames = [
            'Name'                => 'User name',
            'Today'               => 'Current date',
            'Title'               => 'Title',
            'Description'         => 'Description',
            'Type'                => 'Type',
            'Status'              => 'Status',
            'Case_Id'             => 'Case ID',
            'Case_Date'           => 'Case date',
            'Case_Description'    => 'Case description',
            'case_Root_Cause'     => 'Case root cause',
            'case_Recommendation' => 'Case recommnedation',
            'OS_Version'          => 'OS version',
            'sos_Version'         => 'sos version',
        ];

        public function mount()
        {
            $this->tableTitle = __('vault.report_page_title');
            $this->tableDescr = __('vault.report_page_description');
            $this->heading    = __('vault.report_heading');

            if(isset($this->caseid)) {
                $this->case = SupportCase::where('id', $this->caseid)->first();
                $this->did = $this->case->file_id;
                $this->vid = $this->case->vault_id;
            }

            if (request()->boolean('create', false)) {
                if (isset($this->vid, $this->did, $this->caseid)) {
                    $this->buttonId = "report-editor-{$this->vid}-{$this->did}-{$this->caseid}";

                    $this->template = file_get_contents(__DIR__ . "/../../../../../json/reportTemplate.json");

                    $this->dispatch('create-report');
                }
            }
        }

        public function table(Table $table): Table
        {
            $uid = auth()->id();
            return $table
                ->query(
                    Report::query()
                        ->where(function ($q) use ($uid) {
                            $q->where('user_id', $uid)
                              ->orWhere('is_public', true);
                        })
                        ->whereNotIn('id', function ($q) use ($uid) {
                            $q->select('report_id')
                              ->from('user_hidden_reports')
                              ->where('user_id', $uid);
                        })
                        ->with('case')
                )
                ->columns([
                    TextColumn::make('case.case')
                        ->label(__('vault.report_case_label'))
                        ->searchable()
                        ->color('primary')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('title')
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('status')
                        ->badge()
                        ->color(function (string $state): string {
                            if(in_array($state, array_keys($this->statusColors))) {
                                return $this->statusColors[$state];
                            }
                            return "gray";
                        })
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('type')
                        ->color(function (string $state): string {
                            if(in_array($state, array_keys($this->typeColors))) {
                                return $this->typeColors[$state];
                            }
                            return "gray";
                        })
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('description')
                        ->wrap()
                        ->lineClamp(3)
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('excerpt')
                        ->wrap()
                        ->lineClamp(3)
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('name')
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('keywords')
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('updated_at')
                        ->dateTime('d/M/Y')
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    IconColumn::make('is_public')
                        ->label(__('vault.report_public_label'))
                        ->boolean()
                        ->trueIcon('phosphor-globe-duotone')
                        ->falseIcon('')
                        ->trueColor('info')
                        ->tooltip(fn ($state) => $state ? __('vault.report_public_tooltip') : null)
                        ->toggleable(isToggledHiddenByDefault: false),
                ])
                ->defaultSort('status', 'desc')
                ->emptyStateHeading(__('vault.no_data'))
                ->emptyStateDescription(__('vault.report_empty'))
                ->emptyStateIcon('phosphor-empty-duotone')
                ->reorderable('sort')
                ->striped(true)
                ->deferColumnManager(false)
                ->reorderableColumns()
                ->persistSearchInSession()
                ->persistColumnSearchesInSession()
                ->paginated(true)
                ->headerActions([
                    CreateAction::make()
                        ->label(__('vault.report_create_label'))
                        ->modalWidth('6xl')
                        ->extraAttributes([
                            'id' => "$this->buttonId",
                        ])
                        ->createAnother(false)
                        ->visible(fn () => $this->buttonId !== "")
                        ->form([
                            Grid::make(4)
                                ->schema($this->mkForm('create')),
                            $this->mkSection(__('vault.report_new_section'),'create')
                        ])
                        ->using(function (array $data) {
                            $this->buttonId   = "";
                            $data['user_id']  = auth()->user()->id;
                            $data['vault_id'] = $this->vid;
                            $data['dir_id']   = $this->did;
                            $data['case_id']  = $this->caseid;

                            return Report::create($data);
                        })
                        ->after(function (Report $record) {
                            $uid = auth()->id() ?? 0;
                            $payload = (object) ['message' => 'report generated', 'title' => $record->title ?? ''];
                            addEvent($payload, 'GEN_REPORT', 'SUCCESS', 'ACTIVITY', $this->caseid, $this->vid, $uid, $uid);

                            Notification::make()
                                ->success()
                                ->title(__('vault.report_created_success'))
                                ->send();
                        }),
                ])

                ->recordActions([
                    ActionGroup::make([
                        ViewAction::make()
                            ->modalWidth('6xl')
                            ->extraModalFooterActions($this->mkExtraModalFooterActions())
                            ->schema([
                                Section::make('top')
                                    ->heading($this->heading)
                                    ->description($this->description)
                                    ->icon($this->icon)
                                    ->iconSize(IconSize::Large)
                                    ->iconColor($this->color)
                                    ->collapsible()
                                    ->collapsed(true)
                                    ->compact(true)
                                    ->extraAttributes([
                                        'class' => 'text-2xl'
                                    ])
                                    ->schema([
                                        Grid::make(4)
                                        ->schema([
                                            TextEntry::make('case.case')
                                                ->size(TextSize::Medium)
                                                ->color('primary')
                                                ->label(__('vault.report_case_label')),
                                            TextEntry::make('title')
                                                ->size(TextSize::Medium)
                                                ->label(__('vault.report_title_label')),
                                            TextEntry::make('status')
                                                ->color(function (string $state): string {
                                                    if(in_array($state, array_keys($this->statusColors))) {
                                                        return $this->statusColors[$state];
                                                    }
                                                    return "gray";
                                                })
                                                ->size(TextSize::Medium)
                                                ->badge(),
                                            TextEntry::make('type')
                                                ->color(function (string $state): string {
                                                    if(in_array($state, array_keys($this->typeColors))) {
                                                        return $this->typeColors[$state];
                                                    }
                                                    return "gray";
                                                })
                                                ->size(TextSize::Medium),
                                            TextEntry::make('description')
                                                ->columnSpan(2)
                                                ->size(TextSize::Medium)
                                                ->label(__('vault.report_description_label')),
                                            TextEntry::make('excerpt')
                                                ->columnSpan(2)
                                                ->size(TextSize::Medium)
                                                ->label(__('vault.report_excerpt_label')),
                                            TextEntry::make('keywords')
                                                ->size(TextSize::Medium)
                                                ->label(__('vault.report_keywords_label')),
                                            TextEntry::make('updated_at')
                                                ->size(TextSize::Medium)
                                                ->date('d/M/Y')
                                                ->label(__('vault.report_updated_label')),
                                            TextEntry::make('created_at')
                                                ->size(TextSize::Medium)
                                                ->date('d/M/Y')
                                                ->label(__('vault.report_created_label')),
                                        ]),
                                    ]),
                                Fieldset::make('Label')
                                ->label('')
                                ->columns(1)
                                ->schema([
                                    Grid::make(1)
                                    ->schema([
                                        // viewAction
                                        textEntry::make('document')
                                            ->extraAttributes(['class' => 'fi-prose'])
                                            ->hiddenLabel(),
                                    ]),
                                ]),
                            ]),
                        Action::make('open')
                            ->label(__('vault.report_open_case'))
                            ->icon('phosphor-compass-duotone')
                            ->url(fn ($record): string => '/sosbrowser/'.$record->case_id),
                        EditAction::make()
                            ->modalWidth('6xl')
                            ->visible(fn (Report $record): bool => $record->user_id === auth()->id())
                            ->extraModalFooterActions($this->mkExtraModalFooterActions())
                            ->mutateRecordDataUsing(function (array $data): array {
                                $data['updated_at'] = Carbon::parse($data['updated_at'])->format('d/M/Y');
                                $data['created_at'] = Carbon::parse($data['created_at'])->format('d/M/Y');
                                return $data;
                            })
                            ->schema([
                                Section::make('top')
                                    ->heading($this->heading)
                                    ->description($this->description)
                                    ->icon($this->icon)
                                    ->iconSize(IconSize::Large)
                                    ->iconColor($this->color)
                                    ->collapsible()
                                    ->compact(true)
                                    ->extraAttributes([
                                        'class' => 'text-2xl'
                                    ])
                                    ->schema([
                                        Grid::make(4)
                                            ->schema($this->mkForm('edit')),
                                    ]),
                                $this->mkSection(__('vault.report_edit_section'),'edit')
                            ]),
                        Action::make('togglePublic')
                            ->label(fn (Report $record): string => $record->is_public
                                ? __('vault.report_unmark_public')
                                : __('vault.report_mark_public'))
                            ->icon(fn (Report $record): string => $record->is_public
                                ? 'phosphor-globe-x-duotone'
                                : 'phosphor-globe-duotone')
                            ->color(fn (Report $record): string => $record->is_public ? 'warning' : 'info')
                            ->requiresConfirmation()
                            ->visible(fn (): bool => auth()->user()->hasRole('admin'))
                            ->action(function (Report $record): void {
                                $record->update(['is_public' => ! $record->is_public]);
                                Notification::make()
                                    ->success()
                                    ->title($record->is_public
                                        ? __('vault.report_marked_public')
                                        : __('vault.report_marked_private'))
                                    ->send();
                            }),
                        DeleteAction::make()
                            ->visible(fn (Report $record): bool => $record->user_id === auth()->id() || auth()->user()->hasRole('admin'))
                            ->after(function (Report $record) {
                                $uid = auth()->id() ?? 0;
                                $payload = (object) ['message' => 'report deleted', 'title' => $record->title ?? ''];
                                addEvent($payload, 'DEL_REPORT', 'SUCCESS', 'ACTIVITY', $this->caseid, $this->vid, $uid, $uid);

                                Notification::make()
                                    ->success()
                                    ->title(__('vault.report_deleted'))
                                    ->send();
                            })
                            ->mutateDataUsing(function (array $data): array {
                                $data['user_id']  = auth()->user()->id;
                                $data['vault_id'] = $this->vid;
                                $data['dir_id']   = $this->did;
                                $data['case_id']  = $this->caseid;

                                return $data;
                            }),
                        Action::make('hideReport')
                            ->label(__('vault.report_hide_label'))
                            ->icon('phosphor-eye-slash-duotone')
                            ->color('gray')
                            ->requiresConfirmation()
                            ->visible(fn (Report $record): bool => $record->is_public && $record->user_id !== auth()->id())
                            ->action(function (Report $record): void {
                                \DB::table('user_hidden_reports')->insertOrIgnore([
                                    'user_id'   => auth()->id(),
                                    'report_id' => $record->id,
                                ]);
                                Notification::make()
                                    ->success()
                                    ->title(__('vault.report_hidden'))
                                    ->send();
                            }),
                        ])
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options($this->statusOptions),
                    SelectFilter::make('type')
                        ->options($this->typeOptions)
                    ])
                ->toolbarActions([
                    \Filament\Actions\BulkActionGroup::make([
                        \Filament\Actions\DeleteBulkAction::make(),
                    ]),
                ]);
        }

        public function mkSection($heading, $id) {
            $section = Section::make('')
                ->compact(true)
                ->extraAttributes([
                    'class' => 'text-2xl my-1 py-1',
                ])
                ->heading($heading)
                ->icon('phosphor-list-checks-duotone')
                ->iconSize(IconSize::Large)
                ->iconColor('primary')
                ->compact(true)
                ->collapsible()
                ->schema([
                    Tabs::make('Tabs')
                    ->contained(false)
                    ->persistTab()
                    ->extraAttributes([
                        'class' => 'my-0 py-0',
                    ])
                    ->tabs([
                        Tab::make(__('vault.report_edit_tab'))
                            ->extraAttributes([
                                'class' => 'my-2 py-0',
                            ])
                            ->icon("phosphor-note-pencil-duotone")
                            ->schema([
                                RichEditor::make('document')
                                    ->hiddenLabel()
                                    ->key($id)
                                    ->json()
                                    ->placeholder(__('vault.report_write_placeholder'))
                                    ->default($this->template)
                                    ->toolbarButtons([
                                        ['bold', 'italic', 'underline', 'strike', 'subscript', 'superscript', 'link'],
                                        ['h2', 'h3', 'alignStart', 'alignCenter', 'alignEnd'],
                                        ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                                        ['textColor', 'table', 'attachFiles'],
                                        ['undo', 'redo'],
                                        ['customBlocks', 'mergeTags'],
                                    ])
                                    ->mergeTags($this->mergeTagsNames)
                                    ->activePanel('customBlocks')
                                        ->customBlocks([
                                            HostBlock::class,
                                            CpuBlock::class,
                                            MemBlock::class,
                                            DiskBlock::class,
                                            ProcBlock::class,
                                            TcpSocketsBlock::class,
                                            UnixSocketsBlock::class,
                                            ActivityReportBlock::class,
                                            AnnotationsBlock::class,
                                        ])
                            ]),
                        Tab::make(__('vault.report_view_tab'))
                            ->icon("phosphor-eye-duotone")
                            //->visible(fn (): bool => !$this->isAlreadySaved)
                            ->schema([
                                TextEntry::make('document')
                                    ->extraAttributes(['class' => 'fi-prose'])
                                    ->hiddenLabel(),
                            ]),
                    ])
                    ->activeTab(1),
                ]);
            return $section;
        }

        public function mkExtraModalFooterActions() {
            $actions = [
                /*
                Action::make('template')
                    ->label('Save as Template')
                    ->action(function (array $data, EditAction $action) {
                        //
                    }),
                Action::make('post')
                    ->label('Add as a Comment in JIRA ticket')
                    ->action(function (array $data, EditAction $action) {
                        //
                    }),
                */
                Action::make('pdf')
                    ->label(__('vault.report_pdf_label'))
                    ->color('info')
                    ->icon('phosphor-download-duotone')
                    ->action(function (Report $record) {
                        $html = RichContentRenderer::make($record->document)
                            ->customBlocks([
                                HostBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => "host",
                                    'indx' => 0,
                                ],
                                CpuBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => "cpu",
                                    'indx' => 0,
                                ],
                                MemBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => "memory",
                                    'indx' => 0,
                                ],
                                DiskBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => "disk",
                                    'indx' => 0,
                                ],
                                ProcBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => "procs",
                                    'indx' => 0,
                                ],
                                TcpSocketsBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => "conn",
                                    'indx' => 0,
                                ],
                                UnixSocketsBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => "conn",
                                    'indx' => 1,
                                ],
                                ActivityReportBlock::class => [
                                    'vid' => $record->vault_id,
                                    'cid' => $record->case_id,
                                ],
                                AnnotationsBlock::class => [
                                    'vid' => $record->vault_id,
                                    'cid' => $record->case_id,
                                ],
                            ])
                            ->mergeTags([
                                'Name'                => fn (): string => $record->user->name,
                                'Today'               => now()->toFormattedDateString(),
                                'Title'               => fn (): string => $record->title ?? "",
                                'Description'         => fn (): string => $record->description ?? "",
                                'Type'                => fn (): string => $record->type ?? "",
                                'Status'              => fn (): string => $record->status ?? "",
                                'Case_Id'             => fn (): string => $record->case->case ?? "",
                                'Case_Date'           => fn (): string => $record->case->created_at ?? "",
                                'Case_Description'    => fn (): string => $record->case->description ?? "",
                                'case_Root_Cause'     => fn (): string => $record->case->root_cause ?? "",
                                'case_Recommendation' => fn (): string => $record->case->recommendation ?? "",
                                'OS_Version'          => fn (): string => $record->case->os_version ?? "",
                                'sos_Version'         => fn (): string => $record->case->sos_version ?? "",
                            ])
                            ->toHtml();
                        return response()->streamDownload(function () use ($record, $html) {
                            echo Pdf::loadView('pdf.report', [
                                'html' => $html,
                            ])->stream();
                        }, $record->case_id . '.pdf');
                    }),
                Action::make('uploadToItsm')
                    ->label(function (Report $record): string {
                        $ticket = basename(parse_url($record->case->link ?? '', PHP_URL_PATH));
                        return __('vault.report_upload_to_itsm', ['ticket' => $ticket]);
                    })
                    ->color('warning')
                    ->icon('simpleicon-jirasoftware')
                    ->visible(function (Report $record): bool {
                        if (empty($record->case?->link)) {
                            return false;
                        }
                        return ITSMProvider::where('uid', auth()->id())
                            ->where('provider', 'JSM')
                            ->exists();
                    })
                    ->action(function (Report $record) {
                        $html = RichContentRenderer::make($record->document)
                            ->customBlocks([
                                HostBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => 'host',
                                    'indx' => 0,
                                ],
                                CpuBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => 'cpu',
                                    'indx' => 0,
                                ],
                                MemBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => 'memory',
                                    'indx' => 0,
                                ],
                                DiskBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => 'disk',
                                    'indx' => 0,
                                ],
                                ProcBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => 'procs',
                                    'indx' => 0,
                                ],
                                TcpSocketsBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => 'conn',
                                    'indx' => 0,
                                ],
                                UnixSocketsBlock::class => [
                                    'vid'  => $record->vault_id,
                                    'did'  => $record->dir_id,
                                    'cid'  => $record->case_id,
                                    'type' => 'conn',
                                    'indx' => 1,
                                ],
                                ActivityReportBlock::class => [
                                    'vid' => $record->vault_id,
                                    'cid' => $record->case_id,
                                ],
                                AnnotationsBlock::class => [
                                    'vid' => $record->vault_id,
                                    'cid' => $record->case_id,
                                ],
                            ])
                            ->mergeTags([
                                'Name'                => fn (): string => $record->user->name,
                                'Today'               => now()->toFormattedDateString(),
                                'Title'               => fn (): string => $record->title ?? '',
                                'Description'         => fn (): string => $record->description ?? '',
                                'Type'                => fn (): string => $record->type ?? '',
                                'Status'              => fn (): string => $record->status ?? '',
                                'Case_Id'             => fn (): string => $record->case->case ?? '',
                                'Case_Date'           => fn (): string => $record->case->created_at ?? '',
                                'Case_Description'    => fn (): string => $record->case->description ?? '',
                                'case_Root_Cause'     => fn (): string => $record->case->root_cause ?? '',
                                'case_Recommendation' => fn (): string => $record->case->recommendation ?? '',
                                'OS_Version'          => fn (): string => $record->case->os_version ?? '',
                                'sos_Version'         => fn (): string => $record->case->sos_version ?? '',
                            ])
                            ->toHtml();

                        $tmpPath  = tempnam(sys_get_temp_dir(), 'svault-pdf-');
                        $filename = $record->case_id.'.pdf';
                        file_put_contents($tmpPath, Pdf::loadView('pdf.report', ['html' => $html])->output());

                        $ticket = basename(parse_url($record->case->link, PHP_URL_PATH));
                        $jira   = app(JiraService::class);
                        $ok     = $jira->attachFileToIssue(auth()->user(), $ticket, $tmpPath, $filename);

                        @unlink($tmpPath);

                        $uid     = auth()->id() ?? 0;
                        $payload = (object) [
                            'message'  => "PDF report uploaded to ITSM issue {$ticket}",
                            'issue'    => $ticket,
                            'filename' => $filename,
                            'via'      => 'web',
                        ];

                        if ($ok) {
                            Notification::make()
                                ->title(__('vault.report_itsm_upload_success', ['ticket' => $ticket]))
                                ->success()
                                ->send();
                            addEvent($payload, 'PDF_UPLD', 'SUCCESS', 'ACTIVITY', $record->case_id, $record->vault_id, $uid, $uid);
                        } else {
                            notifyError(__('vault.report_itsm_upload_error', ['ticket' => $ticket]));
                            addEvent($payload, 'PDF_UPLD', 'FAILED', 'ACTIVITY', $record->case_id, $record->vault_id, $uid, $uid);
                        }
                    }),
            ];
            return $actions;
        }

        public function mkForm($type) {
            $form = [];
            if($type == 'create') {
                $form[] = TextInput::make('case_id')
                    ->label(__('vault.report_case_label'))
                    ->default(function() {
                        if(isset($this->case)) {
                            return $this->case->case;
                        }
                        return '';
                    })
                    ->disabled();
            } else {
                $form[] = TextInput::make('case_id')
                    ->label(__('vault.report_case_label'))
                    ->dehydrated(false)
                    ->afterStateHydrated(function ($component, $state, $record) {
                        $component->state($record->case->case ?? null);
                    })
                    ->disabled();
            }

            $form[] = TextInput::make('title')
                ->placeholder('Example: Peak CPU usage analysis')
                ->required()
                ->live()
                ->notRegex('/[\$!@#%^*{}=<>+?[\]()|~`;,.\/\\\]+/')    // no strange charcaters complete
                ->maxLength(100)
                ->minLength(3);

            $form[] = Select::make('status')
                ->required()
                ->default('DRAFT')
                ->options($this->statusOptions);

            $form[] = Select::make('type')
                ->required()
                ->default('INCIDENT')
                ->options($this->typeOptions);

            $form[] = Textarea::make('description')
                ->placeholder('Example: The CPU peak was caused by high lattency in the... ')
                ->default(function() {
                    if(isset($this->case)) {
                        return $this->case->description;
                    }
                    return '';
                })
                ->columnSpan(2)
                ->live()
                ->nullable()
                ->maxLength(2048)
                ->minLength(8);

            $form[] = Textarea::make('excerpt')
                ->placeholder('Example: Findings for the CPU usage peak... ')
                ->columnSpan(2)
                ->nullable()
                ->notRegex('/[\$!@#%^*{}[\]|~`\/\\\]+/')    // no strange charcaters no dot
                ->maxLength(600)
                ->minLength(8);

            $form[] = TextInput::make('keywords')
                ->placeholder('Example: The CPU peak was caused by high lattency in the... ')
                ->required()
                ->live()
                ->hidden()
                ->nullable()
                ->notRegex('/[\$!@#%^*{}=<>+?[\]()|~`;,\/\\\]+/')    // no strange charcaters no dot
                ->maxLength(50)
                ->minLength(3);

            return $form;
        }
    }
?>

<x-layouts.app>
    @volt('reports')
        <x-app.container>

            @script
            <script>
                document.title = "SOS Report";
                window.sosViewer.addTab(document.title);
                $wire.on('create-report', () => {
                    setTimeout(() => {document.getElementById('{{ $buttonId }}').click()},500)
                });
            </script>
            @endscript

            <x-filament::section :description="$tableDescr" :heading="$tableTitle" :contained="false"
                icon="phosphor-list-checks-duotone" icon-color="primary" icon-size="lg"
            >

                <div class="overflow-x-auto border rounded-lg">
                    {{ $this->table}}
                </div>

            </x-filament::section>

        </x-app.container>
    @endvolt
</x-layouts.app>

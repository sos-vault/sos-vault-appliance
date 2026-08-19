<?php
    // This component creates a Filament Table with data from Sushi Model SosPlugins.php for each different
    // plugin defined

    use Livewire\Volt\Component;

    use App\Models\SosPlugins;

    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\HtmlString;
    use Illuminate\Support\Number;

    use Filament\Tables;
    use Filament\Tables\Contracts\HasTable;
    use Filament\Tables\Concerns\InteractsWithTable;
    use Filament\Tables\Columns\TextColumn;
    use Filament\Tables\Table;
    use Filament\Tables\Grouping\Group;
    use Filament\Tables\Filters\SelectFilter;
    use Filament\Support\Contracts\TranslatableContentDriver;
    use Filament\Support\Enums\IconPosition;
    use Illuminate\Database\Eloquent\Builder;

    use Filament\Schemas\Concerns\InteractsWithSchemas;
    use Filament\Schemas\Contracts\HasSchemas;
    use Filament\Schemas\Schema;
    use Filament\Schemas\Components\Fieldset;
    use Filament\Infolists\Components\TextEntry;
    use Filament\Support\Enums\Alignment;

    use Filament\Actions\Action;
    use Filament\Actions\Contracts\HasActions;
    use Filament\Actions\Concerns\InteractsWithActions;
    use Filament\Actions\ViewAction;

    new class extends Component implements HasTable, HasActions, HasSchemas
    {
        use InteractsWithTable;
        use InteractsWithSchemas, InteractsWithActions;

        public $data = [];

        public $tableStateIdentifier = "";
        public $description = "Available plugins and profiles in sos v4.11.1";


        public function mount(): void
        {
        }

        public function table(Table $table): Table
        {
            $this->profiles=[
                'ai',
                'ansible',
                'apache',
                'boot',
                'ceph',
                'cifs',
                'cloud',
                'cluster',
                'container',
                'debug',
                'desktop',
                'gpu',
                'hardware',
                'hpc',
                'identity',
                'java',
                'kernel',
                'mail',
                'memory',
                'microshift',
                'mrg',
                'network',
                'nfs',
                'observability',
                'openshift',
                'openstack',
                'openstack_compute',
                'openstack_controller',
                'openstack_edpm',
                'openstack_undercloud',
                'packagemanager',
                'performance',
                'perl',
                'sap',
                'security',
                'services',
                'storage',
                'sysmgmt',
                'system',
                'virt',
                'webserver'
            ];

            // create the table...
            return $table
                ->query(SosPlugins::query()->where('record_state', 'enabled'))
                ->columns([
                    TextColumn::make('name')
                        ->alignment(Alignment::Center)
                        ->limit(14)
                        ->label('name')
                        ->searchable()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('short_description')
                        ->alignment(Alignment::Start)
                        ->label('description')
                        ->width('250px')
                        ->wrap()
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('profiles')
                        ->alignment(Alignment::Start)
                        ->label('profiles')
                        ->badge()
                        ->color('info')
                        ->listWithLineBreaks()
                        ->separator(',')
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: false),
                    TextColumn::make('files')
                        ->alignment(Alignment::Start)
                        ->label('files')
                        ->listWithLineBreaks()
                        ->separator(',')
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('commands')
                        ->alignment(Alignment::Start)
                        ->label('commands')
                        ->listWithLineBreaks()
                        ->separator(',')
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('since_version')
                        ->alignment(Alignment::Center)
                        ->extraAttributes(['class' => 'pr-2'])
                        ->label('since')
                        ->sortable()
                        ->searchable()
                        ->toggleable(isToggledHiddenByDefault: false),
                ])
                ->recordActions([
                    ViewAction::make()
                        ->extraAttributes(['class' => 'mr-4'])
                        ->modalHeading(false)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close')
                        ->modalWidth('2xl')
                        ->schema([
                            Fieldset::make('details')
                                ->columns(1)
                                ->hiddenLabel()
                                ->schema([
                                    TextEntry::make('name')
                                        ->label('Plugin'),
                                    TextEntry::make('long_description')
                                        ->label('Details'),
                                    TextEntry::make('options')
                                        ->hidden(fn ($state): bool => blank($state))
                                        ->html()
                                        ->formatStateUsing(function (string $state): HtmlString {
                                            $html = "";

                                            $options = explode(",", $state);
                                            foreach($options as $option){
                                                $html .= "<li>" . e(trim($option)) . "</li>";
                                            }
                                            return new HtmlString($html);
                                        })
                                        ->label('Options'),
                                    TextEntry::make('profiles')
                                        ->alignment(Alignment::Center)
                                        ->label('profiles')
                                        ->badge()
                                        ->listWithLineBreaks()
                                        ->alignment(Alignment::Start)
                                        ->color('info')
                                        ->separator(','),
                                    TextEntry::make('files')
                                        ->alignment(Alignment::Center)
                                        ->label('files')
                                        ->listWithLineBreaks()
                                        ->alignment(Alignment::Start)
                                        ->separator(','),
                                    TextEntry::make('commands')
                                        ->alignment(Alignment::Center)
                                        ->label('commands')
                                        ->listWithLineBreaks()
                                        ->alignment(Alignment::Start)
                                        ->separator(','),
                                ])
                        ]),
                ])
                ->paginated([10, 25, 50, 100, 'all'])
                ->defaultPaginationPageOption(25)
                ->extremePaginationLinks(true)
                ->defaultSort('plugin_default_state', 'asc')
                ->emptyStateHeading('Could not find any plugin')
                ->emptyStateDescription('No plugins database available')
                ->emptyStateIcon('phosphor-empty-duotone')
                ->striped(true)
                ->deferColumnManager(false)
                ->reorderableColumns()
                ->persistSearchInSession()
                ->persistColumnSearchesInSession()
                ->paginated(true)
                ->description($this->description)
                ->filters([
                    SelectFilter::make('profiles')
                        ->options($this->profiles)
                        ->query(function (Builder $query, $state) {
                            if (!$state) return $query;
                            if (!$state['value']) return $query;
                            if (!$this->profiles[$state['value']]) return $query;

                            $value = $this->profiles[$state['value']];
                            return $query->where('profiles', 'like', '%' . $value . '%');
                        }),
                ]);
        }

        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

    };
?>

<div class="border-1 border-zinc-600 rounded-lg">
    {{ $this->table }}
</div>


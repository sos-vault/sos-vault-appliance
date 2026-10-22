<?php
    // Top Tool main page. This component gets TopData from SosService and calls TopBadge component.
    // TopBadge iterates on components defined in TopData and render a top-table

    use App\Models\SupportCase;

    use App\Providers\SosServiceProvider;
    use App\Providers\VaultTools;

    use Filament\Schemas\Schema;
    use Filament\Schemas\Contracts\HasSchemas;
    use Filament\Schemas\Concerns\InteractsWithSchemas;
    use Filament\Schemas\Components\Grid;
    use Filament\Schemas\Components\Section;
    use Filament\Infolists\Components\TextEntry;
    use Filament\Support\Enums\TextSize;
    use Filament\Support\Enums\IconSize;
    use Filament\Notifications\Notification;
    use Filament\Infolists\Components\ViewEntry;

    use Illuminate\Support\Number;
    use Illuminate\Support\HtmlString;
    use Illuminate\Support\Facades\Log;

    use Livewire\Volt\Component;
    use Livewire\Attributes\On;
    use function Livewire\Volt\{mount, state, computed};

    use function Laravel\Folio\{middleware, name};

    middleware('auth');
    name('chronoview');
    state(['vid','did','caseid']);

    new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public $caseid;
        public $vid;
        public $did;
        public string $type = '';

        private $topData = [];
        public $heading;
        public $description;
        public $icon = "phosphor-mountains-duotone";
        public $color = "info";

        public function mount() {
            $uid = auth()->id() ?? 0;
            if(!isset($this->caseid)) {
                Notification::make()
                    ->title("No case provided")
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                addEvent((object)['message' => 'missing case', 'name' => 'Chronoview'], 'OPEN_TOOL', 'FAILED', 'NORMAL', 0, $this->vid ?? 0, $uid, $uid);
                return;
            }

            $case = SupportCase::where('id', $this->caseid)->first();

            if(!isset($this->vid) && !isset($this->did)) {
                Notification::make()
                    ->title("Missing parameters")
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                addEvent((object)['message' => 'missing vid/did', 'name' => 'Chronoview'], 'OPEN_TOOL', 'FAILED', 'NORMAL', $this->caseid, 0, $uid, $uid);
                return;
            }

            $vtools = new VaultTools(resolveVaultUser($this->vid, $this->caseid, $this->did), $this->vid);
            if($vtools->getVaultId() != $this->vid) {
                Notification::make()
                    ->title("Wrong Vault")
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                addEvent((object)['message' => 'wrong vault', 'name' => 'Chronoview'], 'OPEN_TOOL', 'FAILED', 'NORMAL', $this->caseid, $this->vid, $uid, $uid);
                return;
            }

            if(!$vtools->isOpen()) {
                Notification::make()
                    ->title("Vault closed")
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                addEvent((object)['message' => 'vault closed', 'name' => 'Chronoview'], 'OPEN_TOOL', 'FAILED', 'NORMAL', $this->caseid, $this->vid, $uid, $uid);
                return;
            }

            $dtools = new SosServiceProvider($vtools, $this->vid, $this->did, $this->caseid);
            if(isset($dtools)) {
                $this->topData = $dtools->getTop();
            }

            if(isset($this->topData)) {
                $this->heading = "Top for {$this->topData->host->tableData1->hostname}";
                $this->description = $this->topData->host->tableData1->{'os version'};
                $this->type = 'procs';
            }

            // Hand this open case to Mil (see Summary page) so case questions
            // asked from the Chronoview page inject the live sosreport data.
            rememberMilOpenCase($this->did, $this->caseid, 'Chronoview');

            addEvent((object)['message' => 'tool opened', 'name' => 'Chronoview'], 'OPEN_TOOL', 'SUCCESS', 'NORMAL', $this->caseid, $this->vid, $uid, $uid);
        }

        public function schema(Schema $schema): Schema
        {

            return $schema
                ->components([
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
                            Grid::make(8)
                                ->schema($this->getHostGrid($this->color)),
                            Grid::make(8)
                                ->extraAttributes(['class' => 'dark:bg-zinc-800/50 bg-zinc-100'])
                                ->schema($this->getTasksGrid($this->color)),
                            Grid::make(8)
                                ->schema($this->getCpuGrid($this->color)),
                            Grid::make(8)
                                ->extraAttributes(['class' => 'dark:bg-zinc-800/50 bg-zinc-100' ])
                                ->schema($this->getMemoryGrid($this->color)),
                            Grid::make(8)
                                ->schema($this->getSwapGrid($this->color)),
                        ])
                ]);
        }

        public function getHostGrid($color) {
            // host data
            $hostGrid = [];
            if(isset($this->topData->host->tableData1) && !empty($this->topData->host->tableData1)) {
                $host = $this->topData->host->tableData1;
                $cols = $this->topData->host->tableOrder1;
                $titles = $this->topData->host->tableHeaders1;

                $hostGrid[] = TextEntry::make('host')
                    ->label('.')
                    ->color($color)
                    ->hiddenLabel(true)
                    ->extraEntryWrapperAttributes([
                        'class' => 'h-full items-end pl-8',
                    ])
                    /*
                    ->alignJustify(true)
                    ->alignEnd(true)
                    ->hint('hola')
                    ->visible(false)
                    ->name('')
                    ->hiddenLabel(true)
                    */
                    ->size(TextSize::Small)
                    ->state('Top');

                foreach($cols as $key => $col) {
                    if($col == "") {
                        continue;
                    }

                    $entry = TextEntry::make($host->{$col})
                        ->label(ucfirst($titles[$key]))
                        ->color($color)
                        ->size(TextSize::Small)
                        ->state($host->{$col});

                    switch($col) {
                        case "system time":
                            $entry->columnSpan(2);
                        break;
                        case "load average":
                            $entry->columnSpan(2);
                        break;
                        default:
                            $entry->columnSpan(1);
                        break;

                    }

                    $hostGrid[] = $entry;
                }
            }
            return $hostGrid;
        }

        public function getTasksGrid($color) {
            // tasks data
            $tasksGrid = [];
            if(isset($this->topData->procs->tasks) && !empty($this->topData->procs->tasks)) {
                $tasks = $this->topData->procs->tasks;
                $cols = $this->topData->procs->tasksOrder;

                $tasksGrid[] = TextEntry::make('tasks')
                    ->label('.')
                    ->color($color)
                    ->hiddenLabel(true)
                    ->extraEntryWrapperAttributes([
                        'class' => 'h-full items-end pl-8',
                    ])
                    ->size(TextSize::Small)
                    ->state('Tasks');

                foreach($cols as $col => $title) {
                    if($col == "initial") {
                        continue;
                    }

                    $entry = TextEntry::make($tasks->{$col})
                        ->label(ucfirst($title))
                        ->color($color)
                        ->size(TextSize::Small)
                        ->state($tasks->{$col});

                    $tasksGrid[] = $entry;
                }
            }
            return $tasksGrid;
        }

        public function getCpuGrid($color) {
            // cpu data
            $cpuGrid = [];
            if(isset($this->topData->cpu->tableData1) && !empty($this->topData->cpu->tableData1)) {
                $cpu = $this->topData->cpu->tableData1->cpu;
                $cols = $this->topData->cpu->tableOrder1;
                $titles = $this->topData->cpu->tableHeaders1;

                $cpuGrid[] = TextEntry::make('cpu')
                    ->label('.')
                    ->color($color)
                    ->hiddenLabel(true)
                    ->extraEntryWrapperAttributes([
                        'class' => 'h-full items-end pl-8',
                    ])
                    ->size(TextSize::Small)
                    ->state('CPU');

                foreach($cols as $key => $col) {
                    if($key == 0 ) {
                        continue;
                    }

                    $entry = TextEntry::make($cpu->{$col})
                        ->label(ucfirst($titles[$key]))
                        ->color($color)
                        ->size(TextSize::Small)
                        ->state($cpu->{$col});

                    $cpuGrid[] = $entry;
                }

            }
            return $cpuGrid;
        }

        public function getMemoryGrid($color) {
            // memory data
            $memoryGrid = [];
            if(isset($this->topData->memory->tableData1) && !empty($this->topData->memory->tableData1)) {
                $memory = $this->topData->memory->tableData1;
                $cols = [
                    //"title"       => 'Memory',
                    "total"       => 'Total',
                    "free"        => 'Free',
                    "pfree"       => '% Free',
                    "used"        => 'Used',
                    "pused"       => '% Used',
                    "buff/cache" => 'Buffer/Cache',
                    "pbuff"       => '% Buffer/Cache'
                ];

                $memoryGrid[] = TextEntry::make('memory')
                    ->label('.')
                    ->color($color)
                    ->hiddenLabel(true)
                    ->extraEntryWrapperAttributes([
                        'class' => 'h-full items-end pl-8',
                    ])
                    ->size(TextSize::Small)
                    ->state('Memory');

                foreach($cols as $col => $title) {

                    $entry = TextEntry::make($memory->{$col}->value)
                        ->label($title)
                        ->color($color)
                        ->size(TextSize::Small)
                        ->state($memory->{$col}->value);

                    switch($col) {
                        case "pused":
                        case "pfree":
                        case "pbuff":
                            $entry->numeric(decimalPlaces: 2);
                        break;
                        default:
                        break;

                    }

                    $memoryGrid[] = $entry;
                }
            }
            return $memoryGrid;
        }

        public function getSwapGrid($color) {
            // swap data
            $swapGrid = [];
            if(isset($this->topData->memory->tableData1) && !empty($this->topData->memory->tableData1)) {
                $swap = $this->topData->memory->tableData2;
                $cols = [
                    //"title"       => 'Swap',
                    "total"       => 'Total',
                    "free"        => 'Free',
                    "pfree"       => '% Free',
                    "used"        => 'Used',
                    "pused"       => '% Used',
                ];

                $swapGrid[] = TextEntry::make('swap')
                    ->label('.')
                    ->color($color)
                    ->hiddenLabel(true)
                    ->extraEntryWrapperAttributes([
                        'class' => 'h-full items-end pl-8',
                    ])
                    ->size(TextSize::Small)
                    ->state('Swap');

                foreach($cols as $col => $title) {

                    $entry = TextEntry::make($swap->{$col}->value)
                        ->label($title)
                        ->color($color)
                        ->size(TextSize::Small)
                        ->state($swap->{$col}->value);

                    switch($col) {
                        case "pused":
                        case "pfree":
                        case "pbuff":
                            $entry->numeric(decimalPlaces: 2);
                        break;
                        case "size":
                            $entry->formatStateUsing(fn (string $state) => Number::fileSize($state, 2));
                        break;
                        default:
                        break;

                    }

                    $swapGrid[] = $entry;
                }
            }
            return $swapGrid;
        }
    }
?>

<x-layouts.app>
    @volt('chronoview')
        @if(isset($caseid))
            @script
            <script>
                document.title = "SOS Chronoview";
                window.sosViewer.addTab(document.title);
            </script>
            @endscript

            <div>

                {{ $this->schema }}

                <div class="h-4" ></div>

                @livewire('top-table', [
                    'cid'  => $this->caseid,
                    'vid'  => $this->vid,
                    'did'  => $this->did,
                    'type' => $this->type,
                    'indx' => 0,
                ], key("top-table-{{ $this->vid }}-{{ $this->did }}-{{ $this->caseid }}-{{ $this->type }}-0"))

            </div>
        @endif
    @endvolt
</x-layouts.app>

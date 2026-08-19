<?php
    // STIG Tool main page. This component ...

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
    name('stig');
    state(['vid','did','caseid']);

    new class extends Component implements HasSchemas
    {
        use InteractsWithSchemas;

        public $caseid;
        public $vid;
        public $did;

        public $color = "warning";

        private $topData = [];
        public $heading;
        public $description;

        public function mount() {
            $uid = auth()->id() ?? 0;

            // Hand this open case to Mil (see Summary page) so case questions
            // asked from the Stig page inject the live sosreport data.
            rememberMilOpenCase($this->did, $this->caseid, 'Stig');

            addEvent((object)['message' => 'tool opened', 'name' => 'Stig'], 'OPEN_TOOL', 'SUCCESS', 'NORMAL', $this->caseid ?? 0, $this->vid ?? 0, $uid, $uid);
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

    }
?>

<x-layouts.app>
    @volt('stig')
        @if(isset($caseid))
            <x-filament-actions::modals />

            @script
            <script>
                document.title = "SOS Stig";
                window.sosViewer.addTab(document.title);
                window.addEventListener('sidebar-toggled', window.sosViewer.fixToolControlsSize);
                window.addEventListener('livewire:update', window.sosViewer.fixToolControlsSize);
            </script>
            @endscript

            <div>

                @livewire('tool-controls', [
                    'caseid' => $caseid,
                    'parent' => 'STIG',
                    'color' => $color,
                ])

                <main id='root' wire:ignore class="flex mt-[15.0rem] pb-2 dark:bg-zinc-900 border-gray-200 h-full overflow-none text-sm text-gray-800 dark:text-gray-100">

                    {{-- $this->schema --}}

                </main>

            </div>
        @endif
    @endvolt
</x-layouts.app>

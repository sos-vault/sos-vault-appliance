<?php
    // Compliance Tool main page. CIS/STIG/Docker Bench/exposure findings for
    // this case, produced automatically on upload. Unlicensed by design — no
    // checkAccess()/applianceLicensed() gate here, unlike the Alerts page.

    use Livewire\Volt\Component;

    use function Livewire\Volt\state;
    use function Laravel\Folio\{middleware, name};

    middleware('auth');
    name('compliance-tool');
    state(['vid', 'did', 'caseid']);

    new class extends Component
    {
        public $caseid;
        public $vid;
        public $did;

        public $color = 'warning';

        public function mount()
        {
            $uid = auth()->id() ?? 0;

            rememberMilOpenCase($this->did, $this->caseid, 'Compliance');

            addEvent((object) ['message' => 'tool opened', 'name' => 'Compliance'], 'OPEN_TOOL', 'SUCCESS', 'NORMAL', $this->caseid ?? 0, $this->vid ?? 0, $uid, $uid);
        }
    }
?>

<x-layouts.app>
    @volt('compliance-tool')
        @if(isset($caseid))
            @script
            <script>
                document.title = "SOS Compliance";
                window.sosViewer.addTab(document.title);
                window.addEventListener('sidebar-toggled', window.sosViewer.fixToolControlsSize);
                window.addEventListener('livewire:update', window.sosViewer.fixToolControlsSize);
            </script>
            @endscript

            <div>
                @livewire('tool-controls', [
                    'caseid' => $caseid,
                    'parent' => 'Compliance',
                    'color' => $color,
                ])

                <main id='root' wire:ignore class="flex mt-[15.0rem] pb-2 dark:bg-zinc-900 border-gray-200 h-full overflow-none text-sm text-gray-800 dark:text-gray-100">
                    <livewire:compliance-dashboard
                        :vid="$vid"
                        :did="$did"
                        :caseid="$caseid"
                    />
                </main>
            </div>
        @endif
    @endvolt
</x-layouts.app>

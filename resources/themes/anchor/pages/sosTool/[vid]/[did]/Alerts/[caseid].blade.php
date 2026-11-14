<?php
    // Alerts Tool main page. Create/edit/delete alert rules (grep, threshold,
    // reference-case diff) for this vault. Reused by the File Viewer's
    // "Add an Alert" button via the ?create=1&path=... query string.

    use Livewire\Volt\Component;

    use function Livewire\Volt\state;
    use function Laravel\Folio\{middleware, name};

    middleware('auth');
    name('alerts-tool');
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

            if (isSaas() && ! checkAccess(auth()->user(), 'Alert Rules')) {
                abort(403);
            }

            rememberMilOpenCase($this->did, $this->caseid, 'Alerts');

            addEvent((object) ['message' => 'tool opened', 'name' => 'Alerts'], 'OPEN_TOOL', 'SUCCESS', 'NORMAL', $this->caseid ?? 0, $this->vid ?? 0, $uid, $uid);
        }
    }
?>

<x-layouts.app>
    @volt('alerts-tool')
        @if(isset($caseid))
            @script
            <script>
                document.title = "SOS Alerts";
                window.sosViewer.addTab(document.title);
                window.addEventListener('sidebar-toggled', window.sosViewer.fixToolControlsSize);
                window.addEventListener('livewire:update', window.sosViewer.fixToolControlsSize);
            </script>
            @endscript

            <div>
                @livewire('tool-controls', [
                    'caseid' => $caseid,
                    'parent' => 'Alerts',
                    'color' => $color,
                ])

                <main id='root' wire:ignore class="flex mt-[15.0rem] pb-2 dark:bg-zinc-900 border-gray-200 h-full overflow-none text-sm text-gray-800 dark:text-gray-100">
                    <livewire:alerts-table
                        :vid="$vid"
                        :did="$did"
                        :caseid="$caseid"
                        :prefill-path="request('path')"
                        :auto-create="request()->boolean('create')"
                    />
                </main>
            </div>
        @endif
    @endvolt
</x-layouts.app>

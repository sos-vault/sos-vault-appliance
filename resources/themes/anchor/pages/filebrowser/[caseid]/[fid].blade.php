<?php
use App\Models\SupportCase;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\state;

middleware('auth');
name('filebrowser');
state(['caseid', 'fid']);

new class extends Component
{
    public $caseid;

    public $vid;

    public $did;

    public $fid;

    public $search = '';

    public int $sme = 0;

    public $isTable = 0;

    public $rawMode = 1;

    public $statusLine = '';

    public $errorState = false;

    public $loadingState = false;

    public function mount()
    {
        $this->statusLine = __('vault.file_loading');
        $this->errorState = false;
        $this->loadingState = true;

        $this->sme = (int) request()->query('sme', 0);
        $this->search = (string) request()->query('q', '');

        if ($this->sme > 0) {
            // Shared mode: vid/did come from the shared link query params,
            // not from a SupportCase owned by the current user.
            $this->vid = request()->query('vid');
            $this->did = request()->query('did');

            // Hand this open case to Mil (see Summary page) so case questions
            // asked from the file viewer inject the live sosreport data.
            rememberMilOpenCase($this->did, $this->caseid, 'File Viewer', $this->fid);

            return;
        }

        $case = SupportCase::where('id', $this->caseid)->first();

        if (! isset($case)) {
            $message = __('vault.file_no_case');
            notifyError($message);

            return;
        }

        $this->vid = $case->vault_id;
        $this->did = $case->file_id;

        // Hand this open case to Mil (see Summary page) so case questions asked
        // from the file viewer inject the live sosreport data.
        rememberMilOpenCase($this->did, $this->caseid, 'File Viewer', $this->fid);
    }

    #[On('set-isTable')]
    public function setIsTable()
    {
        $this->isTable = 1;
        $this->rawMode = 0;
    }

    #[On('toggle-raw-mode')]
    public function toggleRawMode()
    {
        $this->rawMode = ! $this->rawMode;
    }

    public function loadMore()
    {
        if (session('lines') >= session('totalLines')) {
            return;
        }

        $this->dispatch('load-more');
        if (session('chunked') && session('chunkCount') > 0) {
            $this->dispatch('open-modal', id: 'load-more');
        }
    }
}
?>

<x-layouts.app>
    @volt('filebrowser')
        <x-app.container-full>
            @if(isset($caseid))
                <x-filament-actions::modals />

                @script
                <script>
                    window.addEventListener('sidebar-toggled', window.sosViewer.fixFileControlsSize);
                    window.addEventListener('livewire:commit', window.sosViewer.fixFileControlsSize);
                    window.addEventListener('toggle-raw', window.sosViewer.fixFileControlsSize);
                    $wire.on('fetch-chunk', window.sosViewer.fetchLogChunk);
                    // Initial sizing once Livewire has fully hydrated the page.
                    $wire.on('load', () => window.sosViewer.fixFileControlsSize());
                    $wire.on('load', () => {
                        window.dispatchEvent(new CustomEvent('toggle-loading'));
                    });
                </script>
                @endscript

                @livewire('file-controls', [
                    'caseid' => $caseid,
                    'fid'    => $fid,
                    'parent' => 'sosViewer',
                    'color'  => 'warning',
                    'sme'    => $sme,
                ])

                <div class="flex w-auto">

                    <!-- Sticky bottom scrollbar -->
                    <div wire:ignore id='float-scroll1' class="hidden fixed bottom-0 w-screen overflow-x-auto bg-transparent z-20" >
                        <div id="stickyInner" class="min-w-[3500px] h-1"></div>
                    </div>

                    <main wire:ignore id="logfile1" class="flex grow wrap mt-64 py-2 border-1 dark:bg-zinc-900 border-gray-200 rounded-lg h-full overflow-none text-sm text-gray-800 dark:text-gray-100 ">

                        {{-- statusBlock --}}
                        <div id="statusBlock" x-show="statusLine || errorState"
                            x-data="{
                                errorState:   @entangle('errorState'),
                                loadingState: @entangle('loadingState'),
                                statusLine :  @entangle('statusLine'),

                            }"
                            @toggle-loading.window="
                                loadingState = true;
                                statusLine = '{{ __('vault.file_loading') }}';
                                errorState = false;
                            "
                            @done-loading.window="
                                loadingState = false;
                                statusLine = null;
                                errorState = false;
                            "
                            class="flex justify-center w-full">

                                <div x-show="statusLine && !errorState" class="flex justify-center items-center w-auto p-4 text-zinc-600 dark:text-zinc-300 my-4 text-sm">

                                    <div x-show="loadingState" x-cloak >
                                        <x-filament::loading-indicator class="h-8 w-8" />
                                    </div>

                                    <span x-text="statusLine"></span>
                                </div>

                                <div x-show="errorState" x-cloak class="flex text-warning-500 mt-4 text-sm">
                                    <x-phosphor-warning-duotone class="w-6 h-6 mr-2" /> {{ __('vault.browser_dir_not_found') }}
                                </div>
                        </div>

                        {{-- raw file (always present)--}}
                        @livewire('file-contents', [
                            'cid'    => $caseid,
                            'vid'    => $vid,
                            'did'    => $did,
                            'fid'    => $fid,
                            'root'   => 'pre1',
                        ])

                        <div x-show="$wire.isTable != 1 || $wire.rawMode == 1" id="rawFile" class="flex w-full min-h-64">
                            <div id="linu1" class="flex flex-col justify-start top-0 pl-2 items-center text-sm h-full text-zinc-500"></div>

                            <div id="contents1" class="flex flex-col justify-start items-start pl-2 h-full w-full" ondrop="window.sosViewer.dropNote(event)" ondragover="window.sosViewer.moveNote(event)">
                                <!-- actual contents -->
                                <div id="acetate1" class="block relative w-full h-full top-0 left-0">
                                    <pre id="pre1" class="block top-0 left-0 text-sm "></pre>
                                </div>

                                <div x-intersect="$wire.loadMore()" class="" style="height: 10px;"></div>
                            </div>
                        </div>

                        {{-- table file --}}
                        <div x-show="$wire.isTable == 1" id="fileTable" class="flex justify-center w-full">
                            @livewire('file-table', [
                                'cid'    => $caseid,
                                'vid'    => $vid,
                                'did'    => $did,
                                'fid'    => $fid,
                                'search' => $search,
                            ])
                        </div>

                        {{-- load more modal --}}
                        <x-filament::modal id="load-more" alignment="center" width="xs">
                            <x-slot name="heading"> </x-slot>

                            <div class="flex self:center flex-col justify-center items-center gap-8">
                                <i class='ph-circle-notch ph-duotone text-primary-700 text-4xl animate-spin'></i>
                                <span class="mb-4">{{ __('vault.file_chunk_loading') }}</span>
                            </div>

                        </x-filament::modal >

                    </main>

                </div>
            @endif

        </x-app.container>
    @endvolt
</x-layouts.app>

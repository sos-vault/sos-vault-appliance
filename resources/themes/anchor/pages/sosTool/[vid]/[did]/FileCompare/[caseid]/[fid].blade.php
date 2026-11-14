<?php
    use App\Models\SupportCase;
    use App\Models\FileContent;
    use App\Providers\VaultTools;

    use App\Helpers\sosVaultHelper;

    use Filament\Notifications\Notification;

    use Illuminate\Support\Facades\Log;
    use Livewire\Volt\Component;
    use Livewire\Attributes\On;
    use function Livewire\Volt\{mount, state, computed};

    use function Laravel\Folio\{middleware, name};

    use Filament\Schemas\Components\Icon;

    middleware('auth');
    name('file-compare');
    state(['vid','did','caseid','fid']);

    new class extends Component
    {
        public ?array $data = [];
        public $caseid;
        public $vid;
        public $did;
        public $fid;

        public $cid2;
        public $did2;
        public $fid2;

        private $DEBUG = false;
        public $color = "info";

        public $filename;
        public $totalLines;

        public bool $lineNumbersShown = true;
        public bool $isLocked = false;
        public bool $isShared = false;
        public bool $highlightEnabled = false;
        public $notes = "";


        public $leftFileContents = '';
        public $rightFileContents = '';

        public $statusLine = '';
        public $errorState = false;
        public $loadingState = false;

        public function mount() {
            $this->statusLine = __('vault.compare_status_select');

            // second case or case on the right
            $this->cid2 = request()->query('cid2');

            if(!isset($this->vid) || !isset($this->did) || !isset($this->caseid)) {
                Notification::make()
                    ->title(__('vault.filecompare_missing_params'))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                $uid = auth()->id() ?? 0;
                addEvent((object)['message' => 'missing params', 'name' => 'FileCompare'], 'OPEN_TOOL', 'FAILED', 'NORMAL', $this->caseid ?? 0, $this->vid ?? 0, $uid, $uid);
                    return false;
            }

            if(!isset($this->fid)) {
                Notification::make()
                    ->title(__('vault.filecompare_missing_fid'))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                $uid = auth()->id() ?? 0;
                addEvent((object)['message' => 'missing fid', 'name' => 'FileCompare'], 'OPEN_TOOL', 'FAILED', 'NORMAL', $this->caseid ?? 0, $this->vid ?? 0, $uid, $uid);
                    return false;
            }

            // first case or case on the left
            $case = SupportCase::where('id', $this->caseid)->first();

            if(!isset($case)) {
                $message = 'No case found. Cannot continue.';
                notifyError($message);
                $uid = auth()->id() ?? 0;
                addEvent((object)['message' => 'case not found', 'name' => 'FileCompare'], 'OPEN_TOOL', 'FAILED', 'NORMAL', $this->caseid ?? 0, $this->vid ?? 0, $uid, $uid);
                return;
            }

            $this->vid = $case->vault_id;
            $this->did = $case->file_id;

            // Hand this open case to Mil (see Summary page) so case questions
            // asked from the FileCompare page inject the live sosreport data.
            rememberMilOpenCase($this->did, $this->caseid, 'File Compare', $this->fid);

            $metadata = FileContent::withParameters([
                'vid' => $this->vid,
                'did' => $this->did,
                'fid' => $this->fid,
                'cid' => $this->caseid,
                'format' => 'raw',
                'source' => 'file-compare',
                ])
                ->where('case_id', $this->caseid)->first();

            if(isset($metadata)) {
                $this->isLocked  = boolval($metadata->locked);
                $this->isShared  = boolval($metadata->status);

                session(['offset' => 0]);
                session(['lines'  => 0]);
                session(['chunked'  => $metadata->chunked]);
                session(['chunkCount'  => 0]);

                $filepath = explode('/', $metadata->name);
                $filename = explode('_', array_pop($filepath));
                $this->filename = $filename[0];
                $this->totalLines = (int)$metadata->totalLines;
            }

            if($this->cid2 != 'null' && isset($this->cid2) && !empty($this->cid2) && $this->cid2 != '') {
                $this->handleSelection($this->cid2);
            }
            $this->dispatch('sidebar-toggled');
            $this->dispatch('livewire:update');
        }

        public function loadMore()
        {
            if(session('lines') >= $this->totalLines) {
                return;
            }

            //load another chunk of file diff
            if(session('chunked')) {
                if(session('chunkCount') == 0) {
                    $n = session('chunkCount');
                    $n++;
                    session(['chunkCount'  => $n]);
                    $this->dispatch('close-modal', id: 'load-more');
                } else {
                    $this->getMoreDiff();
                    $this->dispatch('open-modal', id: 'load-more');
                }
            }
        }

        public function getMoreDiff()
        {
            //we already have all we need fid, caseid, and cid2
            if(!isset($this->caseid)) {
                Notification::make()
                    ->title(__('vault.filecompare_missing_case_left'))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                    return false;
            }

            if(!isset($this->fid)) {
                Notification::make()
                    ->title(__('vault.filecompare_missing_fid_left'))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                    return false;
            }

            if(!isset($this->cid2)) {
                Notification::make()
                    ->title(__('vault.filecompare_missing_case_right'))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                    return false;
            }

            if(!isset($this->fid2)) {
                Notification::make()
                    ->title(__('vault.filecompare_missing_fid_right'))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                    return false;
            }

            $chunkSize = $this->vtools()->chunkSize;
            $offset = session('offset');

            $foundLeft  = $this->vtools()->getFilePathById($this->vid, $this->did, $this->fid, $this->caseid);
            if(!$foundLeft) {
                $message = __('vault.filecompare_left_data_error');
                notifyError($message);
                return false;
            }
            $fileHandleLeft = fopen($foundLeft->filePath, 'rb');
            fseek($fileHandleLeft , $offset);
            $contentsLeft = htmlspecialchars((string) fread($fileHandleLeft, $chunkSize));
            fclose($fileHandleLeft);


            $foundRight  = $this->vtools()->getFilePathById($this->vid, $this->did2, $this->fid2, $this->caseid);
            if(!$foundRight) {
                $message = __('vault.filecompare_right_data_error');
                notifyError($message);
                return false;
            }
            $fileHandleRight = fopen( $foundRight->filePath, 'rb');
            fseek($fileHandleRight , $offset);
            $contentsRight = htmlspecialchars((string) fread($fileHandleRight, $chunkSize));
            fclose($fileHandleRight);

            // Both files empty (e.g. /var/log/boot.log on systemd distros): the JS diff
            // has nothing to render and would silently no-op. Tell the user instead.
            if($offset === 0 && $contentsLeft === '' && $contentsRight === '') {
                $message = __('vault.filecompare_empty_both', ['filename' => $this->filename ?? '']);
                Notification::make()
                    ->title($message)
                    ->icon('phosphor-info-duotone')
                    ->iconColor('warning')
                    ->send();
                return false;
            }

            $this->lines = count(explode("\n", $contentsLeft));
            $this->leftFileContents = base64_encode($contentsLeft);

            $newOffset = $offset + $chunkSize;

            // update FileInfo stats on file-controls componenet
            $this->dispatch('update-offset', [
                'offset' => $newOffset,
                'lines' => $this->lines,
                'chunkSize' => $chunkSize,
            ]);

            $this->dispatch('load-chunk-diff',
                left:  $contentsLeft,
                right: $contentsRight,
            )->to('file-diff-viewer');

            return true;
        }

        #[On('case-chosen')]
        public function handleSelection($caseRight)
        {

            $this->dispatch('sidebar-toggled');

            if(!isset($caseRight) || empty($caseRight) || $caseRight == '' || $caseRight == 'null') {
                $message = __('vault.compare_no_second_case');
                notifyError($message);
                $this->dispatch('setErrorState');
                return null;
            }

            $this->cid2 = $caseRight;


            $lcase = "";
            $rcase = "";

            // vault mount point
            $mountDir = $this->vtools()->getMountPoint();

            // the first case (the left case or case1)
            $case = SupportCase::where('id', $this->caseid)->first();
            $lcase = $case->case;

            //check if fid is a link in which case find the real path
            //$leftFile = $this->vtools()->getFileById($this->fid);

            $leftFileContents = $this->vtools()->getFileContentsById($this->vid, $this->did, $this->fid, 0, $this->caseid);

            if(!isset($leftFileContents) || empty($leftFileContents)) {
                $message = __('vault.filecompare_left_data_error');
                notifyError($message);
                $this->dispatch('setErrorState');
                return;
            }

            // the second case (the right case or case2)
            $case = SupportCase::query()->where('id', $caseRight)->first();
            $rcase = $case->case;
            $this->did2 = $case->file_id;
            $dir = $this->vtools()->getDirById($this->did2);

            if(!isset($dir)) {
                // if the user is here is because the case->file_id (did) no longer matches the id inside .contemts.json
                // (maybe files where moved from one vault to another). Try using the directory name and fix this...

                $dir = $this->vtools()->getDirByName(basename($case->path));

                if(!isset($dir)) {
                    $message = __('vault.filecompare_dir_not_found');
                    notifyError($message);
                    $this->dispatch('setErrorState');
                    return;
                }

                $case->file_id = $dir->id;
                $case->save();
            }

            $rtree = $this->vtools()->getContents("{$mountDir}/{$dir->name}");

            if(!isset($rtree) || empty($rtree)) {
                // at this stage a notification was alredy sent
                return;
            }

            $fileRight = $this->vtools()->find_node_by_attr(
                $rtree->nodes,
                "name", $leftFileContents->name,
                "path", $leftFileContents->path
            );

            if(!isset($fileRight)) {
                $message = __('vault.filecompare_file_not_found');
                notifyError($message);
                $this->dispatch('setErrorState');
                $uid = auth()->id() ?? 0;
                addEvent((object)['message' => 'file not found in right report', 'name' => 'FileCompare', 'filename' => $this->filename ?? ''], 'OPEN_TOOL', 'FAILED', 'NORMAL', $this->caseid, $this->vid, $uid, $uid);
                return;
            }

            $this->fid2 = $fileRight->id;

            // getMoreDiff already surfaced a notification on every failure path,
            // so we only fire the success toast / SUCCESS audit event when it actually rendered.
            if(!$this->getMoreDiff()) {
                return;
            }

            $message = __('vault.filecompare_done', ['filename' => $this->filename, 'lcase' => $lcase, 'rcase' => $rcase]);
            Notification::make()
                ->title($message)
                ->icon('phosphor-bell-ringing-duotone')
                ->iconColor('success')
                ->send();

            $uid = auth()->id() ?? 0;
            addEvent((object)['message' => 'comparison done', 'name' => 'FileCompare', 'filename' => $this->filename ?? ''], 'OPEN_TOOL', 'SUCCESS', 'NORMAL', $this->caseid, $this->vid, $uid, $uid);
            return;
        }

        public function vtools(): VaultTools|null
        {
            if(isset($this->vtools)) {
                return $this->vtools;
            }

            if(!isset($this->vid)) {
                $message = __('vault.dir_no_vault');
                notifyError($message);
                $this->dispatch('setErrorState');
                return null;
            }

            $this->vtools = new VaultTools(resolveVaultUser($this->vid, $this->caseid, $this->did, $this->fid), $this->vid);

            if(!isset($this->vtools)) {
                $message = __('vault.browser_vault_no_access');
                notifyError($message);
                $this->dispatch('setErrorState');
                return null;
            }

            if($this->vtools->getVaultId() != $this->vid) {
                $message = __('vault.dir_wrong_vault');
                notifyError($message);
                $this->dispatch('setErrorState');
                return null;
            }

            if(!$this->vtools->isOpen()) {
                $message = __('vault.dir_vault_closed');
                notifyError($message);
                $this->dispatch('setErrorState');
                return null;
            }

            return $this->vtools;
        }

        #[On('setErrorState')]
        public function setErrorState()
        {
            $this->errorState = true;
            $this->dispatch('sidebar-toggled');
            $this->dispatch('livewire:update');
        }

    }
?>

<x-layouts.app>
    @volt('file-compare')
        <x-app.container-full>
            @if(isset($caseid))
                <x-filament-actions::modals />

                @script
                <script>
                    document.title = "SOS FileCompare {{$this->filename}}";
                    window.sosViewer.addTab("C{{$this->fid}}");
                    $wire.on('fetch-chunk', window.sosViewer.fetchLogChunk);
                    window.addEventListener('livewire:update', window.sosViewer.fixFileControlsSize);
                    window.addEventListener('sidebar-toggled', window.sosViewer.fileCompareSynchWidths);
                    window.addEventListener('load', window.sosViewer.fileCompareSynchWidths);
                    window.addEventListener('resize', window.sosViewer.fileCompareSynchWidths);
                    window.addEventListener('toggle-comparing-on', () => {
                        document.getElementById('containerDiff').innerHTML='';
                        const statusBlock = document.getElementById('statusBlock');
                        statusBlock.classList.replace('hidden', 'flex');
                        window.dispatchEvent(new CustomEvent('toggle-loading'));
                    });
                </script>
                @endscript

                @livewire('file-diff-viewer', [
                    'leftContent' => $leftFileContents,
                    'rightContent' => $rightFileContents,
                ])

                <div class="flex w-auto">

                    @livewire('file-controls', [
                        'caseid' => $caseid,
                        'fid'    => $fid,
                        'parent' => 'fileCompare',
                        'color'  => 'warning',
                        'caseid2' => $cid2,
                    ])

                    <!-- Sticky bottom scrollbar -->
                    <div wire:ignore id="float-scroll1" class="hidden fixed bottom-0 w-screen overflow-x-auto bg-transparent z-20" >
                        <div id="stickyInner" class="min-w-[3500px] h-1"></div>
                    </div>

                    <main id='logfile1' class="relative flex grow wrap mt-60 py-2 border-1 dark:bg-zinc-900 border-gray-200 rounded-lg h-full overflow-none text-sm text-gray-800 dark:text-gray-100 ">

                        <div id="statusBlock" x-show="statusLine || errorState"
                            x-data="{
                                errorState:   @entangle('errorState'),
                                loadingState: @entangle('loadingState'),
                                statusLine :  @entangle('statusLine'),

                            }"
                            @toggle-loading.window="
                                loadingState = true;
                                statusLine = '{{ __('vault.filecompare_comparing') }}';
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
                                    <x-phosphor-warning-duotone class="w-6 h-6 mr-2" /> {{ __('vault.filecompare_error_label') }}
                                </div>
                        </div>

                        <div>
                            <div wire:ignore id='containerDiff' class="flex z-10 flex-1 justify-start items-center" ></div>
                            <div x-intersect="$wire.loadMore()" class="" style="height: 10px;"></div>
                        </div>

                    </main>

                    <x-filament::modal id="load-more" alignment="center" width="xs">
                        <x-slot name="heading"> </x-slot>

                        <div class="flex self:center flex-col justify-center items-center gap-8">
                            <i class='ph-circle-notch ph-duotone text-primary-700 text-4xl animate-spin'></i>
                            <span class="mb-4">{{ __('vault.file_chunk_loading') }}</span>
                        </div>

                    </x-filament::modal >

                </div>
            @endif

        </x-app.container>
    @endvolt
</x-layouts.app>

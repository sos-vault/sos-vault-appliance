<?php
    use App\Models\SupportCase;
    use App\Providers\VaultTools;
    use App\Services\CompareTreeService;

    use App\Helpers\sosVaultHelper;
    use Filament\Notifications\Notification;

    use Illuminate\Support\Facades\Log;
    use Livewire\Volt\Component;
    use Livewire\Attributes\On;
    use function Livewire\Volt\{mount, state, computed};

    use function Laravel\Folio\{middleware, name};

    use Filament\Schemas\Components\Icon;

    middleware('auth');
    name('compare');
    state(['vid','did','caseid']);

    new class extends Component
    {
        public ?array $data = [];
        public $caseid;
        public $vid;
        public $did;
        public $cid2;

        private $DEBUG = false;
        public $color = "info";

        public $treeLeft;
        public $treeRight;

        public $statusLine = '';
        public $errorState = false;
        public $loadingState = false;

        public function mount() {
            $this->statusLine = __('vault.compare_status_select');
            $case = SupportCase::where('id', $this->caseid)->first();
            if(isset($case)) {
                $this->did = $case->file_id;
                $this->vid = $case->vault_id;

                // Hand this open case to Mil (see Summary page) so case questions
                // asked from the Compare page inject the live sosreport data.
                rememberMilOpenCase($this->did, $this->caseid, 'Compare');
            }
        }

        #[On('case-selection-sequece')]
        public function caseSeletcedSequence($cid)
        {
            $this->loadingState = true;
            $this->errorState = false;
            $this->statusLine = "";
            $this->dispatch('clear-diff', cid: $cid);
        }

        #[On('livewire:toggle-loading')]
        public function toggleLoading()
        {
            $this->loadingState = false;
            $this->errorState = false;
            $this->statusLine = "";
            $this->dispatch('livewire:update');
        }

        #[On('livewire:case-chosen')]
        public function handleSelection($cid)
        {
            if(!isset($cid) || empty($cid) || $cid == '' || $cid == 'null') {
                $message = __('vault.compare_no_second_case');
                notifyError($message);
                $this->errorState = true;
                $uid = auth()->id() ?? 0;
                addEvent((object)['message' => 'no second case selected', 'name' => 'Compare'], 'OPEN_TOOL', 'FAILED', 'NORMAL', $this->caseid, $this->vid ?? 0, $uid, $uid);
                return null;
            }

            $lcase = "";
            $rcase = "";

            $this->statusLine = __('vault.compare_calculating');

            $mountDir = $this->vtools()->getMountPoint();

            // the current case
            $case = SupportCase::where('id', $this->caseid)->first();
            $lcase = $case->case;

            // this case first (the current case or left case or case1)
            $dir = $this->vtools()->getDirById($this->did);

            if(!isset($dir)) {
                // if the user is here is because the case->file_id (did) no longer matches the id inside .contemts.json
                // (maybe files where moved from one vault to another). Try using the directory name and fix this...

                $dir = $this->vtools()->getDirByName(basename($case->path));

                if(!isset($dir)) {
                    $message = __('vault.dir_not_found');
                    notifyError($message);
                    $this->errorState = true;
                    return;
                }

                $case->file_id = $dir->id;
                $case->save();
            }

            $ltree = $this->vtools()->getContents("{$mountDir}/{$dir->name}");
            $tree1 = CompareTreeService::flatten($ltree);

            if(empty($tree1)) {
                $message = __('vault.compare_flatten_error');
                notifyError($message);
                $this->errorState = true;
                return;
            }

            // the second case (the selected case or the right case or case2)

            // get did from second case
            $this->cid2 = $cid;
            $case = SupportCase::query()->where('id', $cid)->first();
            $rcase = $case->case;
            $did2 = $case->file_id;
            $dir = $this->vtools()->getDirById($did2);

            if(!isset($dir)) {
                // if the user is here is because the case->file_id (did) no longer matches the id inside .contemts.json
                // (maybe files where moved from one vault to another). Try using the directory name and fix this...

                $dir = $this->vtools()->getDirByName(basename($case->path));

                if(!isset($dir)) {
                    $message = __('vault.dir_not_found');
                    notifyError($message);
                    $this->errorState = true;
                    return;
                }

                $case->file_id = $dir->id;
                $case->save();
            }

            $rtree = $this->vtools()->getContents("{$mountDir}/{$dir->name}");
            $tree2 = CompareTreeService::flatten($rtree);

            if(empty($tree2)) {
                $message = __('vault.compare_flatten_error');
                notifyError($message);
                $this->errorState = true;
                return;
            }

            // mega tree
            $allPaths = array_unique(array_merge(
                array_keys($tree1),
                array_keys($tree2)
            ));

            // create the CHANGES database
            $changes = [];
            foreach ($allPaths as $path) {
                $status = "";
                if (!isset($tree1[$path])) {
                    $status = 'missing_left';
                    $node = $tree2[$path];
                } elseif (!isset($tree2[$path])) {
                    $status = 'missing_right';
                    $node = $tree1[$path];
                } else {
                    $a = $tree1[$path];
                    $b = $tree2[$path];

                    if ($a != $b) {
                        $status = 'different';
                        $node = $a;
                    }
                }

                $node['__status'] = $status;
                $changes[$path] = $node;
            }

            if(!isset($changes) || empty($changes) ) {
                $message = __('vault.compare_changes_error');
                notifyError($message);
                $this->errorState = true;
                return;
            }
            unset($tree1, $tree2);


            // Build hash indexes once; markNodes uses these for O(1) lookup per change.
            $leftIndex = [];
            $rightIndex = [];
            CompareTreeService::buildIndex($ltree->nodes, $leftIndex);
            CompareTreeService::buildIndex($rtree->nodes, $rightIndex);

            $this->statusLine = __('vault.compare_marking_different');
            CompareTreeService::markNodes($changes, 'different', $rtree, $ltree, $rightIndex, $leftIndex);

            $this->statusLine = __('vault.compare_marking_missing_left');
            CompareTreeService::markNodes($changes, 'missing_left', $rtree, $ltree, $rightIndex, $leftIndex);

            $this->statusLine = __('vault.compare_marking_missing_right');
            CompareTreeService::markNodes($changes, 'missing_right', $ltree, $rtree, $leftIndex, $rightIndex);

            // Both trees now hold the merged path layout — ship the left tree to both panes.
            $leftJson = json_encode($ltree);
            $this->treeLeft = base64_encode($leftJson);
            $this->treeRight = base64_encode($leftJson);

            $this->dispatch('openSosReport',
                cid:  $this->caseid,
                vid:  $this->vid,
                did:  $this->did,
                root: 'root1',
                mode: 'compareLeft',
                tree:  $this->treeLeft,
                cid2:  $this->cid2,
            )->to('directory-tree');

            $this->dispatch('openSosReport',
                cid:  $this->caseid,
                vid:  $this->vid,
                did:  $this->did,
                root: 'root2',
                mode: 'compareRight',
                tree:  $this->treeRight,
                cid2:  $this->cid2,
            )->to('directory-tree');

            $message = __('vault.compare_done', ['lcase' => $lcase, 'rcase' => $rcase]);
            //log::info($message);
            Notification::make()
                ->title($message)
                ->icon('phosphor-bell-ringing-duotone')
                ->iconColor('success')
                ->send();

            $uid = auth()->id() ?? 0;
            addEvent((object)['message' => 'comparison done', 'name' => 'Compare'], 'OPEN_TOOL', 'SUCCESS', 'NORMAL', $this->caseid, $this->vid, $uid, $uid);
            $this->toggleLoading();
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
                $this->errorState = true;
                return null;
            }

            $this->vtools = new VaultTools(resolveVaultUser($this->vid, $this->caseid, $this->did), $this->vid);

            if(!isset($this->vtools)) {
                $message = __('vault.browser_vault_no_access');
                notifyError($message);
                $this->errorState = true;
                return null;
            }

            if($this->vtools->getVaultId() != $this->vid) {
                $message = __('vault.dir_wrong_vault');
                notifyError($message);
                $this->errorState = true;
                return null;
            }

            if(!$this->vtools->isOpen()) {
                $message = __('vault.dir_vault_closed');
                notifyError($message);
                $this->errorState = true;
                return null;
            }

            return $this->vtools;
        }

    }
?>

<x-layouts.app>
    @volt('compare')
        <x-app.container-full>
            @if(isset($caseid))
                <x-filament-actions::modals />

                @script
                <script>
                    document.title = "SOS Compare";
                    window.sosViewer.addTab(document.title);
                    $wire.on('refresh-this', window.sosViewer.filelistRefresh);
                    window.addEventListener('sidebar-toggled', window.sosViewer.fixToolControlsSize);
                    window.addEventListener('livewire:update', window.sosViewer.fixToolControlsSize);
                    window.addEventListener('clear-diff', (event) => {
                        document.getElementById('root1').innerHTML='';
                        document.getElementById('root2').innerHTML='';
                        const data = { 'cid': event.detail.cid, };
                        window.dispatchEvent(new CustomEvent('livewire:case-chosen', {detail: data}));
                    });
                </script>
                @endscript

                @livewire('directory-tree', [
                    'cid'    => $this->caseid,
                    'vid'    => $this->vid,
                    'did'    => $this->did,
                    'root'   => 'root1',
                    'mode'   => 'compareLeft',
                    'tree'   => $treeLeft,
                    'cid2'    => $this->cid2,
                ])

                @livewire('directory-tree', [
                    'cid'    => $this->caseid,
                    'vid'    => $this->vid,
                    'did'    => $this->did,
                    'root'   => 'root2',
                    'mode'   => 'compareRight',
                    'tree'   => $treeRight,
                    'cid2'    => $this->cid2,
                ])

                @livewire('tool-controls', [
                    'caseid' => $caseid,
                    'parent' => 'Compare',
                    'color' => $color,
                ])

                <main id='root' wire:ignore class="flex mt-[15.0rem] pb-2 dark:bg-zinc-900 border-gray-200 h-full overflow-none text-sm text-gray-800 dark:text-gray-100 tree ">

                    {{-- case1 --}}
                    <div class="flex w-1/2">

                        <div class="mt-2 border-1 rounded-lg grow w-full max-w-full">
                            <div id="loading" class="flex justify-center p-4 w-full">

                                <div x-data="{ errorState: @entangle('errorState') }">
                                    <div x-show="errorState" class="flex text-warning-500 mt-4 text-sm" />
                                        <x-phosphor-warning-duotone class="w-6 h-6 mr-2" /> {{ __('vault.browser_dir_not_found') }}
                                    </div>
                                </div>

                                <div x-data="{
                                    errorState:   @entangle('errorState'),
                                    loadingState: @entangle('loadingState'),
                                    statusLine :  @entangle('statusLine'),
                                }">
                                    <x-filament::loading-indicator x-show="!errorState && loadingState" class="h-8 w-8" />
                                    <div x-show="!errorState && !loadingState" class="flex text-zinc-600 dark:text-zinc-300 my-4 text-sm" />
                                        <span wire:text="statusLine">{{ $statusLine }}</span>


                                    </div>
                                </div>

                                <div id="root1"></div>

                            </div>
                        </div>

                    </div>


                    {{-- case2 --}}
                    <div class="flex w-1/2">

                        <div class="mt-2 border-1 rounded-lg grow w-full max-w-full">
                            <div id="loading2" class="flex justify-center p-4 w-full">

                                <div x-data="{ errorState: @entangle('errorState') }">
                                    <div x-show="errorState" class="flex text-warning-500 mt-4 text-sm" />
                                        <x-phosphor-warning-duotone class="w-6 h-6 mr-2" /> {{ __('vault.browser_dir_not_found') }}
                                    </div>
                                </div>

                                <div x-data="{
                                    errorState:   @entangle('errorState'),
                                    loadingState: @entangle('loadingState'),
                                    statusLine :  @entangle('statusLine'),
                                }">
                                    <x-filament::loading-indicator x-show="!errorState && loadingState" class="h-8 w-8" />
                                    <div x-show="!errorState && !loadingState" class="flex text-zinc-600 dark:text-zinc-300 my-4 text-sm" />
                                        <span wire:text="statusLine">{{ $statusLine }}</span>


                                    </div>
                                </div>

                                <div id="root2"></div>

                            </div>
                        </div>

                    </div>

                </main>
            @endif

            @if(!empty($fileData))
                @livewire('file-list-context-menu', ['fileData' => $fileData])
            @endif

            <div wire:click="$refresh" id="fileListRefresh" class="hidden"></div>
        </x-app.container>
    @endvolt
</x-layouts.app>

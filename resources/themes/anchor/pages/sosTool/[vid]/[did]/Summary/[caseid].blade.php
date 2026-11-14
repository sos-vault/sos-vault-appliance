<?php
    // Summary Tool main page. This component gets summaryData from SosService and calls SummaryBadge component.
    // SummaryBadge iterates on components defined in summaryData and uses filament.widgets.stats-overview-widget blade
    // to display a custom Filament StatsOverviewWidget (resources/views/filament/widgets/stats-overview-widget.blade.php)
    // Each StatsOverviewWidget opens a modal using the summary-table-modal blade upon a click event.
    // Depending on the component, summary-table-modal will render a summary-table or a view-host-info component on the

    use App\Models\SupportCase;

    use App\Providers\SosServiceProvider;
    use App\Providers\VaultTools;

    use Filament\Notifications\Notification;
    use Illuminate\Support\Facades\Log;
    use Livewire\Volt\Component;
    use Livewire\Attributes\On;
    use function Livewire\Volt\{mount, state, computed};

    use function Laravel\Folio\{middleware, name};

    middleware('auth');
    name('summary');
    state(['vid','did','caseid']);

    new class extends Component {

        public ?array $data = [];
        public $caseid;
        public $vid;
        public $did;

        private $summaryData = [];
        public ?string $heading;
        public ?string $description;

        public function mount() {
            $uid = auth()->id() ?? 0;
            if(!isset($this->caseid)) {
                Notification::make()
                    ->title(__('vault.tool_missing_params'))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                addEvent((object)['message' => 'missing params', 'name' => 'Summary'], 'OPEN_TOOL', 'FAILED', 'NORMAL', 0, $this->vid ?? 0, $uid, $uid);
                return;
            }
            $case = SupportCase::where('id', $this->caseid)->first();

            if(!isset($this->vid) && !isset($this->did)) {
                Notification::make()
                    ->title(__('vault.tool_missing_params'))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                addEvent((object)['message' => 'missing vid/did', 'name' => 'Summary'], 'OPEN_TOOL', 'FAILED', 'NORMAL', $this->caseid, 0, $uid, $uid);
                return;
            }

            $vtools = new VaultTools(resolveVaultUser($this->vid, $this->caseid, $this->did), $this->vid);
            if($vtools->getVaultId() != $this->vid) {
                Notification::make()
                    ->title(__('vault.dir_wrong_vault'))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                addEvent((object)['message' => 'wrong vault', 'name' => 'Summary'], 'OPEN_TOOL', 'FAILED', 'NORMAL', $this->caseid, $this->vid, $uid, $uid);
                return;
            }

            if(!$vtools->isOpen()) {
                Notification::make()
                    ->title(__('vault.dir_vault_closed'))
                    ->icon('phosphor-bell-ringing-duotone')
                    ->iconColor('danger')
                    ->send();
                addEvent((object)['message' => 'vault closed', 'name' => 'Summary'], 'OPEN_TOOL', 'FAILED', 'NORMAL', $this->caseid, $this->vid, $uid, $uid);
                return;
            }

            $dtools = new SosServiceProvider($vtools, $this->vid, $this->did, $this->caseid);
            if(isset($dtools)) {
                $this->summaryData = $dtools->getSummary();
            }

            if(isset($this->summaryData)) {
                $this->heading = __('vault.summary_page_heading', ['hostname' => $this->summaryData->host->tableData1->hostname]);
                $this->description = $this->summaryData->host->tableData1->{'os version'};
            }

            // Hand this open case to Mil so case questions asked from the Summary
            // page get the live sosreport data (the widget mounts after this slot).
            rememberMilOpenCase($this->did, $this->caseid, 'Summary');

            addEvent((object)['message' => 'tool opened', 'name' => 'Summary'], 'OPEN_TOOL', 'SUCCESS', 'NORMAL', $this->caseid, $this->vid, $uid, $uid);
        }
    }
?>

<x-layouts.app>
    @volt('summary')
        @if(isset($caseid))
            @script
            <script>
                document.title = "SOS Summary";
                window.sosViewer.addTab(document.title);
            </script>
            @endscript
            <div>
                <x-app.heading title="{{ $heading }}" description="{{ $description}}" :border="false" />
                @livewire(App\Livewire\SummaryBadge::class, [
                    'summaryData' => $this->summaryData,
                    'vid' => $this->vid,
                    'did' => $this->did,
                    'cid' => $this->caseid,
                ])
                <x-filament-actions::modals />
                <livewire:summary-table-modal />
            </div>
        @endif
    @endvolt
</x-layouts.app>

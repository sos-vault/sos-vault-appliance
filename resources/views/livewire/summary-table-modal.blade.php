<?php
    // This component will render a modal with a Filament Table or a view-host-info component. It is called
    // from the custom Filament StatsOverviewWidget component.

    use Livewire\Volt\Component;
    use Livewire\Attributes\On;

    use Illuminate\Support\Facades\Log;

    use App\Providers\VaultTools;
    use App\Providers\SosServiceProvider;
    use App\Helpers\sosVaultHelper;

    new class extends Component
    {
        public string $type = '';
        public $vid;
        public $did;
        public $cid;

        public $heading;
        public $heading2;
        public $color = "primary";
        public $modalWidth = "8xl";

        public $summaryData;
        public $secondTable = false;

        private $tables = [];
        private $titles = [""];

        private $vtools;

        // Listen for the widget click
        #[On('open-summary-modal')]
        public function openModal($type,$vid,$did,$cid): void
        {
            if (!isset($vid, $did, $cid, $type)) {
                $message = "Missing parameters. Cannot continue.";
                notifyError($message);
                $this->errorState($message);
                return;
            }

            $this->type = $type;
            $this->vid  = $vid;
            $this->did  = $did;
            $this->cid  = $cid;

            if(!isset($this->vid)) {
                $message = "No vault provided. Cannot continue.";
                notifyError($message);
                $this->errorState($message);
                return;
            }

            $this->vtools = new VaultTools(resolveVaultUser($this->vid, $this->cid, $this->did), $this->vid);

            if(!isset($this->vtools)) {
                $message = "Couldn't access your vault. Cannot continue.";
                notifyError($message);
                $this->errorState($message);
                return;
            }

            if($this->vtools->getVaultId() != $this->vid) {
                $message = "Wrong vault provided. Cannot continue.";
                notifyError($message);
                $this->errorState($message);
                return;
            }

            if(!$this->vtools->isOpen()) {
                $message = "Your vault is closed. Cannot continue.";
                notifyError($message);
                $this->errorState($message);
                return;
            }


            $dtools = new SosServiceProvider($this->vtools, $this->vid, $this->did, $this->cid);

            if(!isset($dtools)) {
                $message = "Could not open data tools. Cannot continue.";
                notifyError($message);
                $this->errorState($message);
                return;
            }

            $this->summaryData  = $dtools->getSummary();

            if(!isset($this->summaryData)) {
                $message = "Could not get summary data. Cannot continue.";
                notifyError($message);
                $this->errorState($message);
                return;
            }

            $this->secondTable = false;
            $this->heading   = $this->summaryData->{$this->type}->description;
            $this->modalWidth = "8xl";

            $smallModals = ["cpu","memory","conn","host","tcpip"];
            if(in_array($this->type, $smallModals)) {
                $this->modalWidth = "6xl";
            }

            $this->dispatch('open-modal', id: "summary-modal");

            //find all tables...
            $keys = array_keys((array)$this->summaryData->{$this->type});
            $this->tables = explode(",", implode(",", preg_grep("/tableData\d+/", $keys)));

            $this->color = $this->summaryData->{$this->type}->badgeData->color;

            //find all tables titles...
            $this->titles = [];
            foreach($this->tables as $table) {
                // one day I will put the title of the tables for the conn widget inside the data
                // (like other widgets) in SosServiceProvider.php so I can get rid of this two lines:
                $N = str_replace("tableData", "", $table);
                $externalTitle = "tableTitle{$N}";

                if(isset($this->summaryData->{$this->type}->{$table})) {
                    if(isset($this->summaryData->{$this->type}->{$externalTitle})) {

                            $this->titles[] = $this->summaryData->{$this->type}->{$externalTitle};

                    } else if(isset($this->summaryData->{$this->type}->{$table}->title)) {

                        if(str_contains($this->summaryData->{$this->type}->{$table}->title, "Info")) {
                            $this->titles[] = $this->summaryData->{$this->type}->{$table}->title;
                        } else {
                            if($this->type == "errors") {
                                $this->titles[] = $this->summaryData->{$this->type}->{$table}->title . " Errors";
                            } else {
                                $this->titles[] = ucfirst($this->summaryData->{$this->type}->{$table}->title) . " Info";
                            }
                        }
                    } else if(isset($this->summaryData->{$this->type}->description)) {

                        if(str_contains($this->summaryData->{$this->type}->description, "Info")) {
                            $this->titles[] = $this->summaryData->{$this->type}->description;
                        } else {
                            $this->titles[] = ucfirst($this->summaryData->{$this->type}->description) . " Info";
                        }
                    }
                }
            }
        }
    };
?>

<x-filament::modal
    id="summary-modal"
    alignment="center"
    width="{{ $this->modalWidth }}"
    :close-by-clicking-away="false"
    :close-by-escaping="false"
    :close-button="true"
>
    <x-slot name="heading"></x-slot>

    @if($this->type == "host")
        <div class="font-bold text-{{ $this->color }}-600">{{ $this->titles[0] }}</div>
        @livewire('view-host-info', ['record' => (array)$this->summaryData->{$this->type}->tableData1 ])
    @else
        @foreach($this->tables as $table)
            @if($loop->first)
                <livewire:summary-table
                    :vid="$vid"
                    :did="$did"
                    :cid="$cid"
                    :type="$type"
                    :indx="$loop->index"
                    wire:key="summary-table-{{ $vid }}-{{ $did }}-{{ $cid }}-{{ $type }}-{{ $loop->index }}"
                />
            @else
                <div class="mt-4">
                    <livewire:summary-table
                        :vid="$vid"
                        :did="$did"
                        :cid="$cid"
                        :type="$type"
                        :indx="$loop->index"
                        wire:key="summary-table-{{ $vid }}-{{ $did }}-{{ $cid }}-{{ $type }}-{{ $loop->index }}"
                    />

                </div>
            @endif
        @endforeach
    @endif

</x-filament::modal>

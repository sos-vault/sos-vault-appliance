<?php
    // This component creates a Filament Table with data from Sushi Model SummaryData.php for each different
    // summary component defined in SosDataProvider->summaryData

    use Livewire\Volt\Component;

    use App\Models\SummaryData;
    use App\Models\SupportCase;

    use App\Providers\VaultTools;
    use App\Providers\SosServiceProvider;
    use App\Helpers\sosVaultHelper;

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

    use Filament\Actions\Action;
    use Filament\Actions\Contracts\HasActions;
    use Filament\Actions\Concerns\InteractsWithActions;

    new class extends Component implements HasTable, HasActions, HasSchemas
    {
        use InteractsWithTable;
        use InteractsWithSchemas, InteractsWithActions;

        public $vid;
        public $did;
        public $cid;
        public $type;
        public $indx;

        public $summaryData;
        public $dateFields = [];
        public $stringFields  = [];
        public $bytesFields  = [];
        public $jsonFields  = [];

        public $orders  = [];
        public $headers = [];
        public $titles = [];

        public $tableStateIdentifier = "";
        public $tableTitle = "";
        public $tableColor = "";
        public bool $isCollapsed = false;
        public $pollingEnabled = true;

        private $vtools;

        public function disablePolling(): void
        {
            $this->pollingEnabled = false;
        }

        public function mount(): void
        {
            if (!isset($this->did, $this->cid, $this->type, $this->indx)) {
                $message = "Missing parameters. Cannot continue.";
                notifyError($message);
                $this->errorState($message);
                return;
            }

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

            /*
            if(!isset($this->cid)) {
                $message = "No case provided. Cannot continue.";
                notifyError($message);
                $this->errorState($message);
                return;
            }
            $case = SupportCase::where('id', $this->cid)->first();
            */

            $dtools = new SosServiceProvider($this->vtools, $this->vid, $this->did, $this->cid);
            if(isset($dtools)) {
                $this->summaryData  = $dtools->getSummary();
                $this->dateFields   = $dtools->dateFields;
                $this->stringFields = $dtools->stringFields;
                $this->bytesFields  = $dtools->bytesFields;

                $keys = array_keys((array)$this->summaryData->{$this->type});
                $this->orders = explode(",", implode(",", preg_grep("/tableOrder\d+/", $keys)));
                $this->headers = explode(",", implode(",", preg_grep("/tableHeaders\d+/", $keys)));
                $this->titles  = explode(",", implode(",", preg_grep("/tableTitle\d+/", $keys)));
                $this->tableStateIdentifier = "summary-table-{$this->vid}-{$this->did}-{$this->cid}-{$this->type}-{$this->indx}";
            }
        }

        public function table(Table $table): Table
        {
            // initialization
            $columns         = [];
            $filters         = [];
            $options         = [];
            $filterNames     = [];
            $groups          = [];

            // hold the column names for which an icon will be appyied
            $withIcon        = [];

            // hold the column names for which column color will be appyied
            $withColor       = [];

            // hold the column names for which description will be appyied
            $withDescription = [];

            // column names and order
            $names           = [];

            // table header names
            $labels          = [];

            // hold the column names for which badge color will be appyied
            $statf           = [];

            // columns for which the table will have filters
            $filterBy        = [];

            // N columns names that will be hidden by default
            $toggledOff      = [];

            $color  = "primary";

            // column controls
            $isToggled    = false;
            $isDate       = false;
            $isString     = false;
            $isSize       = false;
            $isNumber     = false;
            $isSortable   = true;
            $isLogEntry   = false;
            $bytePrecison      = 2;
            $numberPrecison    = 0;

            // table controls
            $isPaginated  = true;
            $defaultPaginationPageOption  = '10';
            $isToggleable = true;
            $sortColumn   = "";
            $sortOrder    = "desc";
            $searchPlaceHolder  = "";
            $hideGroupsControls = false;

            // both table and column controls
            $isSearchable = true;
            $defaultGroup = "";

            // multitable controls
            isset($this->orders[$this->indx]) && $order  = $this->orders[$this->indx];
            isset($this->headers[$this->indx]) && $header = $this->headers[$this->indx];
            isset($this->titles[$this->indx]) && $title  = $this->titles[$this->indx];

            if(isset($this->summaryData) && isset($this->type)) {
                !empty($order) && $names   = $this->summaryData->{$this->type}->{$order};
                !empty($header) && $labels  = $this->summaryData->{$this->type}->{$header};
                !empty($title) && $this->tableTitle = $this->summaryData->{$this->type}->{$title};

                if(isset($this->summaryData->{$this->type}->badgeData->color)) {
                    $color   = $this->summaryData->{$this->type}->badgeData->color;
                    $this->tableColor   = $this->summaryData->{$this->type}->badgeData->color;
                }

                if(isset($this->summaryData->{$this->type}->badgeData->state)) {
                    if(!empty($this->summaryData->{$this->type}->badgeData->state)) {
                        $statf   = $this->summaryData->{$this->type}->badgeData->state;
                    }
                }

                // setings per widget
                switch($this->type) {
                    case "host":
                        $isSearchable = false;
                        $isSortable   = false;
                        $isPaginated  = false;
                        $isToggleable = false;
                    break;
                    case "cpu":
                        $sortColumn = "cpu";
                        $isSearchable  = false;
                        $numberPrecison = 2;

                        // add cpu to string fileds
                        $this->stringFields[] = "cpu";
                    break;
                    case "memory":
                        $isSearchable  = false;
                        $isSortable  = false;
                        $isPaginated  = false;
                        $numberPrecison = 2;
                    break;
                    case "disk":
                        $searchPlaceHolder = __('vault.summary_search_disk');
                        $numberPrecison = 2;
                        $this->jsonFields = [
                            "r/s",
                            "rkB/s",
                            "rrqm/s",
                            "%rrqm",
                            "r_await",
                            "rareq-sz",
                            "w/s",
                            "wkB/s",
                            "wrqm/s",
                            "%wrqm",
                            "w_await",
                            "wareq-sz",
                            "aqu-sz",
                            "util",
                            "tps",
                            "d/s",
                            "dkB/s",
                            "drqm/s",
                            "%drqm",
                            "d_await",
                            "dareq-sz",
                            "f/s",
                            "f_await",
                        ];
                        $this->bytesFields = ['size','used'];
                        $this->stringFields  = [
                            "point","pused",
                            "isize", "iused","ipused",
                            "dtype","fstype",
                            "majmin",
                            "pvolumes",
                            "moptions",
                        ];
                        $toggledOff = [
                            "majmin",
                            //"pvolumes",
                            "moptions",
                            "r/s",
                            "rkB/s",
                            "rrqm/s",
                            "%rrqm",
                            "r_await",
                            "rareq-sz",
                            "w/s",
                            "wkB/s",
                            "wrqm/s",
                            "%wrqm",
                            "w_await",
                            "wareq-sz",
                            "aqu-sz",
                            //"util",
                            //"tps",
                            "d/s",
                            "dkB/s",
                            "drqm/s",
                            "%drqm",
                            "d_await",
                            "dareq-sz",
                            "f/s",
                            "f_await",
                        ];
                        $sortColumn = "used";
                    break;
                    case "limits":
                    case "procs":
                        $sortColumn = "PID";
                        $sortOrder = "asc";
                        $searchPlaceHolder = __('vault.summary_search_proc');
                        $numberPrecison = 2;

                        // hide the las N columns...
                        $toggledOff = array_slice($names, -5);
                        if($this->type == "limits") {
                            $toggledOff = array_slice($names, -10);
                            $numberPrecison = 0;
                        }

                        $filterBy = ["USER","STAT"];

                        // values for the filters
                        $options["USER"] = ["root" => "root", "host1" => "host1", "www-data" => "www-data"];
                        $options["STAT"] = [
                            "running"  => "running",
                            "sleeping" => "sleeping",
                            "idle"     => "kernel idle",
                            "stopped"  => "stopped",
                            "zombie"   => "zombie",
                            "uninterruptible"   => "uninterruptible",
                        ];

                        $groups = [
                            Group::make('PPID')
                                ->label(__('vault.summary_group_ppid')),
                            Group::make('USER')
                                ->label(__('vault.summary_group_user')),
                            Group::make('STAT')
                                ->label(__('vault.summary_group_state')),
                            Group::make('TTY')
                                ->label(__('vault.summary_group_tty')),
                        ];

                    break;
                    case "inventory":
                        $defaultGroup = "title";
                        $hideGroupsControls = true;
                        $groups = [
                            Group::make('title')
                                ->collapsible()
                                ->titlePrefixedWithLabel(false)
                                ->orderQueryUsing(fn (Builder $query, string $direction) => $query->orderBy('order', $direction))
                                ->getTitleFromRecordUsing(function($record) {
                                    return($record->title);
                                })
                                ->getDescriptionFromRecordUsing(function($record): HtmlString {

                                    //phosphor-stethoscope-duotone
                                    $icon = "";
                                    $iconString = explode('-', $record->icon);
                                    $iconType = array_pop($iconString);
                                    $iconFamily = array_shift($iconString);
                                    $iconName = preg_replace("/-$/", "", implode('-', $iconString));
                                    switch($iconFamily) {
                                        case "phosphor":
                                            $icon = sprintf("ph-%s ph-%s h-5 w-5 text-2xl ", $iconType, $iconName);
                                        break;
                                        case "fas":
                                            $icon = sprintf("far-%s fa-%s h-5 w-5 text-2xl ", $iconType, $iconName);
                                        break;
                                    }

                                    $html  = '<div class="flex justify-start gap-4 px-4 py-2 text-primary-600">';
                                        $html .= "<i class='{$icon}'></i>";
                                        $html .= "<span>{$record->descr}</span>";
                                    $html .= '</div>';
                                    return new HtmlString($html);
                                })
                                ->label('title'),
                        ];

                        $isSearchable  = true;
                        $isSortable  = true;
                        $isPaginated  = true;
                    break;
                    case "firewall":
                        $defaultGroup = "chain";
                        $hideGroupsControls = true;
                        $groups = [
                            Group::make('chain')
                                ->collapsible()
                                ->titlePrefixedWithLabel(false)
                                ->label(__('vault.summary_group_chain')),
                        ];

                        $toggledOff = ["chain"];
                        $isSearchable  = true;
                        $isSortable  = true;
                        $isPaginated  = true;
                    break;
                    case "errors":
                        $defaultGroup = "logfile";
                        $hideGroupsControls = true;
                        $groups = [
                            Group::make('logfile')
                                ->collapsible()
                                ->getTitleFromRecordUsing(function($record): HtmlString {
                                    $label = $record->logfile;
                                    $label .= " <span style='color:#DC2626;' >[{$record->errorcount}]</span>";
                                    return new HtmlString($label);
                                })
                        ];

                        $toggledOff = ["logfile","errorcount"];
                        $isSearchable  = true;
                        $isSortable  = true;
                        $isPaginated  = [10, 25, 50, 'all'];
                        $defaultPaginationPageOption  = 'all';
                        $isLogEntry = true;

                    break;
                    case "systemd":
                        $defaultGroup = "type";
                        $hideGroupsControls = true;
                        $searchPlaceHolder = __('vault.summary_search_systemd');
                        $groups = [
                            Group::make('type')
                                ->collapsible()
                                ->orderQueryUsing(fn (Builder $query, string $direction) => $query->orderBy('typeorder', $direction))
                                ->getTitleFromRecordUsing(function($record): HtmlString {
                                    $countColor = $record->typefailed > 0 ? '#ef4444' : '#9ca3af';
                                    $label = $record->type;
                                    $label .= " <span style='color:{$countColor};' >[{$record->typecount}]</span>";
                                    return new HtmlString($label);
                                })
                        ];

                        $toggledOff = ["type","typecount"];
                        $isSearchable  = true;
                        $isSortable  = true;
                        $isPaginated  = [10, 25, 50, 'all'];
                        $defaultPaginationPageOption  = 'all';
                        $this->stringFields  = [ "type", "unit", "loaded", "active", "sub", "job", "description" ];

                    break;
                    case "conn":
                        if($this->indx == 0) {
                            $defaultGroup = "Proto";
                            $groups = [
                                Group::make('Proto')
                                    ->label(__('vault.summary_group_protocol')),
                                Group::make('State')
                                    ->label(__('vault.summary_group_state2')),
                                Group::make('PID')
                                    ->label(__('vault.summary_group_pid')),
                                Group::make('Local_Address')
                                    ->label(__('vault.summary_group_local_addr')),
                                Group::make('Foreign_Address')
                                    ->label(__('vault.summary_group_remote_addr')),
                            ];

                            $filterBy = ["Proto","State"];

                            // values for the filters
                            $options["Proto"] = ["tcp" => "tcp", "udp" => "udp"];
                            $options["State"] = [
                                "LISTEN"  => "LISTEN",
                                "TIME_WAIT" => "TIME_WAIT",
                                "FIN_WAIT" => "FIN_WAIT",
                                "FIN_WAIT2" => "FIN_WAIT2",
                                "CLOSE_WAIT" => "CLOSE_WAIT",
                                "ESTABLISHED"     => "ESTABLISHED",
                            ];
                        }

                        if($this->indx == 1) {
                            $filterBy = ["State"];

                            // values for the filters
                            $options["State"] = [
                                "LISTENING"  => "LISTENING",
                                "CONNECTED"     => "CONNECTED",
                            ];
                        }

                        $isSearchable  = true;
                        $isSortable  = true;
                        $isPaginated  = true;
                    break;
                    case "tcpip":

                        if($this->indx == 0) {
                            $this->stringFields  = ["GENERAL_DEVICE", "IP4_ADDRESS", "IP6_ADDRESS","GENERAL_MTU","GENERAL_STATE"];
                            $isSearchable = false;
                            $isSortable   = false;
                            $isPaginated  = false;
                            $isToggleable = false;
                        }

                        if($this->indx == 1) {
                            $this->stringFields  = ['Name','Value','Descr'];
                            $isSearchable = false;
                            $isSortable   = false;
                            $isPaginated  = false;
                            $isToggleable = false;
                        }

                        if($this->indx == 2) {
                            $this->stringFields  = ["Name", "Value", "Percentage", "Descr", "Reference", "Category"];
                            $sortColumn = "Order";
                            $sortOrder  = "asc";

                            $withDescription  = ['Descr'];
                            $withIcon  = ['Value'];
                            $withColor = ['Value','Percentage'];
                            $defaultGroup = "IPv4";
                            $groups = [
                                Group::make('Category')
                                    ->label(__('vault.summary_group_protocol')),
                            ];

                            $filterBy = ["Category"];
                            $toggledOff = ["Icon","Color","Hint"];

                            // values for the filters
                            $options["Category"] = [
                                "IPv4"      => "IPv4",
                                "IPv6"      => "IPv6",
                                "IPExt"     => "IPExt",
                                "ICMP"      => "ICMP",
                                "ICMPv6"    => "ICMPv6",
                                "TCP"       => "TCP",
                                "TCPExt"    => "TCPExt",
                                "UDP"       => "UDP",
                                "UDPv6"     => "UDPv6",
                                "UDPLite"   => "UDPLite",
                                "UDPLitev6" => "UDPLitev6",
                                "MPTCPExt"  => "MPTCPExt",
                                "NDv6"      => "NDv6",
                            ];
                            $filterNames = [
                                "Category" => "Protocol",
                            ];

                            $isSearchable  = true;
                            $isSortable  = true;
                            $isPaginated  = true;
                        }
                    break;
                    case "files":
                    case "kernel":
                    case "packages":
                    default:
                        $isSearchable  = true;
                        $isSortable  = true;
                        $isPaginated  = true;
                    break;
                }

                // create Text Columns...
                for($i=0; $i < count($names); $i++) {

                    $isToggled = in_array($names[$i], $toggledOff);
                    $isDate    = in_array($names[$i], $this->dateFields);
                    $isString  = in_array($names[$i], $this->stringFields);
                    $isSize    = in_array($names[$i], $this->bytesFields);
                    $isNumber  = (!$isDate && !$isString && !$isSize);
                    $isJson    = in_array($names[$i], $this->jsonFields);

                    // create a column...
                    $column = TextColumn::make($names[$i])
                        ->wrapHeader()
                        ->label($labels[$i]);

                    if(in_array($names[$i], $withIcon)) {
                        $column->icon(fn ($record) => $record->Icon);
                        if(in_array($names[$i], $withColor)) {
                            $column->iconColor(fn ($record) => $record->Color);
                        }
                    }

                    if(in_array($names[$i], $withColor)) {
                        //grab the color from the Color column
                        $column->color(fn ($record) => $record->Color);
                    }
                    if(in_array($names[$i], $withDescription)) {
                        //grab the color from the Color column
                        $column->description(fn ($record) => $record->Hint);
                    }

                    if($isToggleable) {
                        $column->toggleable(isToggledHiddenByDefault: $isToggled);
                    }

                    // Filament's generate_search_column_expression() treats column names
                    // containing '(' as raw SQL (e.g. lower(), json_extract()), which breaks
                    // searchable() for headers like "Max open files (files)".
                    if($isSearchable && !str_contains($names[$i], '(')) {
                        $column->searchable(isIndividual: false);
                    }

                    if($isLogEntry) {
                        $column->wrap();
                    }

                    if($isSortable) {
                        $column->sortable();
                    }

                    // format MB
                    if($isSize) {
                        $column->formatStateUsing(fn (string $state): string => Number::fileSize(floatval($state), precision: $bytePrecison));
                    }

                    // format numbers
                    if($isNumber) {
                        $column->formatStateUsing(fn (string $state): string => Number::format(floatval($state), precision: $numberPrecison));
                    }

                    // format json fields
                    if($isJson) {
                        // only disk/iostat columns are of this type for now

                        $column->formatStateUsing(function (string $state): float {
                            $data = json_decode($state);
                            $textf = floatval(Number::format($data->value, precision: 3));
                            return($textf);
                        });

                        $column->suffix(function (string $state): string {
                            $data = json_decode($state);
                            return(" {$data->units}");
                        });

                        $column->tooltip(function (string $state): string {
                            $data = json_decode($state);
                            return($data->descr);
                        });
                    }

                    if(!empty($statf) && in_array($names[$i], $statf)) {
                        $column->color($color);
                    }

                    // badge for stat in proc table
                    if($names[$i] == "STAT") {
                        $column->badge();
                        $column->color(fn (string $state): string => match ($state){
                            "running"  => "primary",
                            "sleeping" => "gray",
                            "idle"     => "info",
                            "stopped"  => "warning",
                            "zombie"   => "danger",
                            "uninterruptible"   => "gray",
                            default            => "gray",
                        });
                    }

                    // badge for state in conn table
                    if($names[$i] == "State") {
                        $column->badge();
                        $column->color(fn (string $state): string => match ($state){
                            "LISTEN"      => "info",
                            "LISTENING"      => "info",
                            "TIME_WAIT"   => "gray",
                            "ESTABLISHED" => "primary",
                            "CLOSE_WAIT"  => "warning",
                            "CLOSING"     => "warning",
                            "LAST_ACK"    => "warning",
                            "FIN_WAIT"    => "danger",
                            "FIN_WAIT_1"  => "danger",
                            "FIN_WAIT_2"  => "danger",
                            "N/A"         => "white",
                            "-"           => "white",
                            "SYN_SENT"    => "warning",
                            "SYN_RECEIVED"    => "warning",
                            "CONNECTED"   => "primary",
                            default       => "black",
                        });
                    }

                    // badge for active state in systemd table
                    if($names[$i] == "active") {
                        $column->badge();
                        $column->color(fn (string $state): string => match (strtolower($state)){
                            "active"   => "primary",
                            "failed"   => "danger",
                            "inactive" => "gray",
                            "activating"   => "warning",
                            "deactivating" => "warning",
                            "reloading"    => "warning",
                            default        => "gray",
                        });
                    }

                    // format LSOF_FILENAMES in files table as a textarea
                    if($names[$i] == "LSOF_FILENAMES") {
                        $column->separator("\n")
                            ->color($color)
                            ->listWithLineBreaks()
                            ->limitList(5)
                            ->expandableLimitedList();
                    }

                    // format title in inventory table as a textarea
                    if($names[$i] == "data") {
                        $column->separator("\n")
                            ->listWithLineBreaks()
                            ->expandableLimitedList();
                    }

                    // format Descr in kernel table as a textarea
                    if($names[$i] == "Descr") {
                        $column->wrap();
                    }

                    $columns[] = $column;

                    // add filters...
                    if(in_array($names[$i], $filterBy)) {
                        $filter = SelectFilter::make($names[$i])->options($options[$names[$i]]);

                        if(isset($filterNames) && is_array($filterNames) && !empty($filterNames)) {
                            $filter->label($filterNames[$names[$i]]);
                        }

                        $filters[] = $filter;
                    }
                }
            }

            // create the table...
            $table->query(SummaryData::withParameters([
                    'vid' => $this->vid,
                    'did' => $this->did,
                    'cid' => $this->cid,
                    'type' => $this->type,
                    'indx' => $this->indx,
                ])->newQuery())
                ->emptyStateHeading(__('vault.summary_empty_heading'))
                //->emptyStateDescription('Try the Regenerate Data button from the View Cases menu.')
                ->emptyStateIcon('phosphor-empty-duotone')
                ->queryStringIdentifier($this->tableStateIdentifier)
                ->columns($columns)
                //->reorderable('sort')
                ->paginated($isPaginated)
                ->defaultPaginationPageOption($defaultPaginationPageOption)
                ->striped(true)
                ->collapsedGroupsByDefault()
                ->deferColumnManager(false);

            if($isToggleable) {
                $table->reorderableColumns();
            }

            // wait a bit for the other tables
            if($this->indx > 0) {
                $table->deferLoading();
            }

            if($this->indx > 1) {
                $table->poll(fn () => ($this->pollingEnabled) ? '1s' : null);
            }

            if(!empty($groups) && isset($defaultGroup)) {
                $table->groups($groups)
                    ->defaultGroup($defaultGroup);

                if($hideGroupsControls) {
                    $table->groupingSettingsHidden();
                }
            }

            if($isSearchable) {
                $table->persistSearchInSession()
                    ->persistColumnSearchesInSession()
                    ->searchPlaceholder($searchPlaceHolder)
                    ->searchDebounce(false)
                    ->splitSearchTerms(false);
            }

            if(!empty($filters)) {
                $table->filters($filters);
            }

            if($sortColumn && $sortOrder) {
                $table->defaultSort($sortColumn, $sortOrder)
                    ->persistSortInSession();
            }

            // errors table: per-row action to open the logfile in the File Viewer,
            // pre-searched to the matching error message (best-effort).
            if($this->type == "errors") {
                $table->recordActions([
                    Action::make('viewInFile')
                        ->iconButton()
                        ->icon('phosphor-arrow-square-out-duotone')
                        ->tooltip(__('vault.summary_view_in_file'))
                        ->color('gray')
                        ->url(fn ($record) => route('filebrowser', [
                            'caseid' => $this->cid,
                            'fid'    => $record->fid,
                            'q'      => $record->search,
                        ]))
                        ->openUrlInNewTab()
                        ->visible(fn ($record) => (int) $record->fid > 0),
                ]);
            }

            return $table;
        }

        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

    };
?>

<div class="border-1 border-zinc-600 rounded-lg">

    @script
    <script>
        // ── Search term highlighter ──────────────────────────────────────────────
        function highlightTableSearch(root, term) {
            // Remove previous highlights
            root.querySelectorAll('mark[data-hl]').forEach(m => {
                m.replaceWith(document.createTextNode(m.textContent));
            });

            if (!term || term.length < 2) return;

            const re = new RegExp(
                `(${term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi'
            );

            root.querySelectorAll('table td').forEach(cell => {
                Array.from(cell.childNodes).forEach(node => {
                    //if (node.nodeType !== Node.TEXT_NODE || !node.textContent.trim()) return;
                    if (!node.textContent.trim()) return;
                    const highlighted = node.textContent.replace(re,
                        '<mark data-hl class="bg-warning-200 dark:bg-warning-200 rounded px-0.5">$1</mark>'
                    );
                    if (highlighted !== node.textContent) {
                        const span = document.createElement('span');
                        span.innerHTML = highlighted;
                        node.replaceWith(span);
                    }
                });
            });
        }

@if($type === "errors")
        // ── Error keyword highlighter (mirrors the getErrorsData() regex) ──────────
        // Single pass so the error + search highlights never clobber each other.
        function highlightErrors(root, term) {
            // Restore original text (unwrap our previous highlight spans) so the
            // Filament cell markup — and its column wrapping — stays intact.
            root.querySelectorAll('span[data-hl-wrap]').forEach(s => {
                s.replaceWith(document.createTextNode(s.textContent));
            });
            root.querySelectorAll('table td').forEach(cell => cell.normalize());

            const safeTerm = (term && term.length >= 2)
                ? term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
                : null;

            const re = new RegExp(
                safeTerm ? `(${safeTerm})|(error|critic|oom)` : `(error|critic|oom)`,
                'gi'
            );

            root.querySelectorAll('table td').forEach(cell => {
                // Only ever wrap text nodes — never replace element nodes — so the
                // cell structure (wrapped message column included) is preserved.
                const walker = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT, {
                    acceptNode: n => n.nodeValue.trim() ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT,
                });
                const nodes = [];
                let n;
                while ((n = walker.nextNode())) nodes.push(n);

                nodes.forEach(node => {
                    const text = node.nodeValue;
                    const highlighted = text.replace(re, (full, g1) => {
                        if (safeTerm && g1 !== undefined) {
                            return `<mark data-hl class="bg-warning-200 dark:bg-warning-200 rounded px-0.5">${g1}</mark>`;
                        }
                        return `<mark data-hl-err class="bg-danger-200 dark:bg-danger-200 text-danger-900 rounded px-0.5">${full}</mark>`;
                    });
                    if (highlighted !== text) {
                        const span = document.createElement('span');
                        span.setAttribute('data-hl-wrap', '');
                        span.innerHTML = highlighted;
                        node.replaceWith(span);
                    }
                });
            });
        }
@endif

        Livewire.hook('commit', ({ component, succeed }) => {
            if (component.el !== $el) return;
            succeed(() => {
                requestAnimationFrame(() => {
                    const term = ((component.snapshot?.data?.tableSearch) ?? '').trim();
@if($type === "errors")
                    highlightErrors($el, term);
@else
                    highlightTableSearch($el, term);
@endif
                });
            });
        });

@if($type === "errors")
        // Errors table renders its rows server-side with no wire:init commit to
        // trigger the hook above, so paint once on load (no $wire access).
        requestAnimationFrame(() => highlightErrors($el, ''));
@endif
    </script>
    @endscript

    @if($type == "tcpip" && $indx > 1)
        <div x-data="{}" wire:init="disablePolling" x-init="setTimeout(() => { $wire.disablePolling() }, 5000)"></div>
    @endif

    @if($type == "conn" || ($type == "tcpip" && $indx < 2))
        @if($type == "tcpip" && $indx < 2)
            @php
                $isCollapsed=true;
            @endphp
        @endif

        <x-filament::section collapsible :collapsed="$isCollapsed" >

            <x-slot name="heading">
                <span class="text-{{ $tableColor }}-600">{{ $tableTitle }}</span>
            </x-slot>

            {{ $this->table }}

        </x-filament::section>
    @else
        <x-filament::section>

            <x-slot name="heading">
                <span class="text-{{ $tableColor }}-600">{{ $tableTitle }}</span>
            </x-slot>

            {{ $this->table }}

        </x-filament::section>
    @endif

</div>


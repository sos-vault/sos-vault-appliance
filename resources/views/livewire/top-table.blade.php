<?php
    // This component creates a Filament Table with data from Sushi Model ToolData.php for each different
    // summary component defined in SosDataProvider->topData

    use Livewire\Volt\Component;

    use App\Models\TopData;
    use App\Models\SupportCase;

    use App\Helpers\sosVaultHelper;

    use App\Providers\VaultTools;
    use App\Providers\SosServiceProvider;

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

        public $topData;
        public $dateFields = [];
        public $stringFields  = [];
        public $bytesFields  = [];

        public $orders  = [];
        public $headers = [];

        public $tableStateIdentifier = "";

        private $vtools;

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

            $dtools = new SosServiceProvider($this->vtools, $this->vid, $this->did, $this->cid);

            if(isset($dtools)) {
                $this->topData  = $dtools->getTop();
                $this->dateFields   = $dtools->dateFields;
                $this->stringFields = $dtools->stringFields;
                $this->bytesFields  = $dtools->bytesFields;

                $keys = array_keys((array)$this->topData->{$this->type});
                $this->orders = explode(",", implode(",", preg_grep("/tableOrder\d+/", $keys)));
                $this->headers = explode(",", implode(",", preg_grep("/tableHeaders\d+/", $keys)));
                $this->tableStateIdentifier = "summary-table-{$this->vid}-{$this->did}-{$this->cid}-{$this->type}-{$this->indx}";
            }
        }

        public function table(Table $table): Table
        {
            $columns = [];
            $filters = [];
            $options = [];
            $groups  = [];

            $names  = [];
            $labels = [];
            $color  = "primary";
            $statf  = [];

            // columns for which the table will have filters
            $filterBy   = [];

            // N columns names that will be hidden by default
            $toggledOff = [];

            $isPaginated  = true;
            $isToggled    = false;
            $isToggleable = true;
            $isDate       = false;
            $isString     = false;
            $isSize       = false;
            $isNumber     = false;
            $isSearchable = true;
            $isSortable   = true;
            $hideGroupsControls = false;

            $sortColumn        = "";
            $sortOrder         = "desc";
            $searchPlaceHolder = "";
            $bytePrecison      = 2;
            $numberPrecison    = 0;
            $defaultGroup      = "";

            $order  = $this->orders[$this->indx];
            $header = $this->headers[$this->indx];

            if(isset($this->topData) && isset($this->type)) {
                $names   = $this->topData->{$this->type}->{$order};
                $labels  = $this->topData->{$this->type}->{$header};

                if(isset($this->topData->{$this->type}->badgeData->color)) {
                    $color   = $this->topData->{$this->type}->badgeData->color;
                }

                if(isset($this->topData->{$this->type}->badgeData->state)) {
                    if(!empty($this->topData->{$this->type}->badgeData->state)) {
                        $statf   = $this->topData->{$this->type}->badgeData->state;
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
                    case "procs":
                        $sortColumn = "PID";
                        $sortOrder = "asc";
                        $searchPlaceHolder = __('vault.summary_search_proc');
                        $numberPrecison = 2;

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

                    // create a column...
                    $column = TextColumn::make($names[$i])
                        ->wrapHeader()
                        ->label($labels[$i]);

                    if($isToggleable) {
                        $column->toggleable(isToggledHiddenByDefault: $isToggled);
                    }

                    if($isSearchable) {
                        $column->searchable(isIndividual: false);
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
                            default => "gray",
                        });
                    }

                    $columns[] = $column;

                    // add filters...
                    if(in_array($names[$i], $filterBy)) {
                        $filters[] = SelectFilter::make($names[$i])
                            ->options($options[$names[$i]]);
                    }
                }
            }

            // create the table...
            $table->query(TopData::withParameters([
                    'vid' => $this->vid,
                    'did' => $this->did,
                    'cid' => $this->cid,
                    'type' => $this->type,
                    'indx' => $this->indx,
                ])->newQuery())
                ->emptyStateHeading(__('vault.top_empty_heading'))
                ->defaultPaginationPageOption(5)
                //->emptyStateDescription('Try the Regenerate Data button from the View Cases menu.')
                ->emptyStateIcon('phosphor-thumbs-down-duotone')
                ->queryStringIdentifier($this->tableStateIdentifier)
                ->columns($columns)
                //->reorderable('sort')
                ->paginated($isPaginated)
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

            return $table;
        }

        public function makeFilamentTranslatableContentDriver(): ?TranslatableContentDriver
        {
            return null;
        }

    };
?>

<div class="border-1 border-zinc-600 rounded-lg">
    {{ $this->table }}
</div>


<?php

namespace App\Livewire;

use App\Models\FileContent;
use Carbon\Carbon;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Component;

class FileTable extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms;
    use InteractsWithTable;

    #[Locked]
    public $vid;

    #[Locked]
    public $did;

    #[Locked]
    public $cid;

    #[Locked]
    public $fid;

    public $ini;

    public $fin;

    public $tz;

    public $search = '';

    public function render()
    {
        return view('livewire.file-table');
    }

    public function mountInteractsWithTable(): void
    {
        $this->dispatch('done-loading');

        // Pre-position the table when opened from an error row (?q=...).
        if (filled($this->search)) {
            $this->tableSearch = $this->search;
        }

        // Read log range directly from the file metadata — never rely on session
        // since session may be stale (different file, shared mode, race condition).
        $meta = FileContent::withParameters([
            'vid' => $this->vid,
            'did' => $this->did,
            'fid' => $this->fid,
            'cid' => $this->cid,
            'format' => 'raw',
            'source' => 'file-table-mount',
        ])->where('case_id', $this->cid)->first();

        if ($meta && $meta->isLogFile && $meta->ini_date) {
            $this->tz = $meta->tz ?: 'UTC';
            $this->ini = Carbon::parse($meta->ini_date.' '.$meta->ini_time, $this->tz);
            $this->fin = Carbon::parse($meta->fin_date.' '.$meta->fin_time, $this->tz);

            // Keep session in sync for other components that still read it.
            session([
                'isLogFile' => true,
                'ini_date' => $meta->ini_date,
                'ini_time' => $meta->ini_time,
                'fin_date' => $meta->fin_date,
                'fin_time' => $meta->fin_time,
                'tz' => $this->tz,
            ]);
        }
    }

    public function table(Table $table): Table
    {
        $table->query(FileContent::withParameters([
            'vid' => $this->vid,
            'did' => $this->did,
            'fid' => $this->fid,
            'cid' => $this->cid,
            'format' => 'table',
            'source' => 'file-table',
        ])->newQuery())
            ->columns($this->generateColumns())
            ->emptyStateHeading(__('vault.file_table_empty_heading'))
            ->emptyStateDescription(__('vault.file_table_empty_description'))
            ->emptyStateIcon('phosphor-empty-duotone')
            ->striped(true)
            ->deferColumnManager(false)
            ->deferFilters(false)
            ->reorderableColumns()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->paginated(['10', '25', '50', '100', 'all']);

        if ($this->ini !== null || session('isLogFile')) {
            $table->defaultSort('line', 'asc');

            // logfiles can be filtered by date and time independently
            $table->filters([
                Filter::make('time_from')
                    ->label(__('vault.file_table_filter_time_from'))
                    ->form([
                        TimePicker::make('time_from')
                            ->label(__('vault.file_table_filter_time_from'))
                            ->default(fn () => $this->ini?->format('H:i:s') ?? session('ini_time', '00:00:00'))
                            ->minutesStep(5)
                            ->secondsStep(5)
                            ->seconds(true),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['time_from'] ?? null, fn ($q, $d) => $q->where('time', '>=', $d))
                    )
                    ->indicateUsing(fn (array $data) => isset($data['time_from'])
                        ? __('vault.file_table_filter_time_from').': '.($data['time_from'] ?? '')
                        : null
                    ),

                Filter::make('time_until')
                    ->label(__('vault.file_table_filter_time_until'))
                    ->form([
                        TimePicker::make('time_until')
                            ->label(__('vault.file_table_filter_time_until'))
                            ->default('23:59:59')
                            ->minutesStep(5)
                            ->secondsStep(5)
                            ->seconds(true),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['time_until'] ?? null, fn ($q, $d) => $q->where('time', '<=', $d))
                    )
                    ->indicateUsing(fn (array $data) => isset($data['time_until'])
                        ? __('vault.file_table_filter_time_until').': '.($data['time_until'] ?? '')
                        : null
                    ),

                Filter::make('date_from')
                    ->label(__('vault.file_table_filter_date_from'))
                    ->form([
                        DatePicker::make('date_from')
                            ->label(__('vault.file_table_filter_date_from'))
                            ->default(fn () => $this->ini?->format('Y-m-d') ?? session('ini_date')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['date_from'] ?? null, fn ($q, $d) => $q->where('date', '>=', date('M d', strtotime($d))))
                    )
                    ->indicateUsing(fn (array $data) => isset($data['date_from'])
                        ? __('vault.file_table_filter_date_from').': '.($data['date_from'] ?? '')
                        : null
                    ),

                Filter::make('date_until')
                    ->label(__('vault.file_table_filter_date_until'))
                    ->form([
                        DatePicker::make('date_until')
                            ->label(__('vault.file_table_filter_date_until'))
                            ->default(fn () => $this->fin?->format('Y-m-d') ?? session('fin_date')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['date_until'] ?? null, fn ($q, $d) => $q->where('date', '<=', date('M d', strtotime($d))))
                    )
                    ->indicateUsing(fn (array $data) => isset($data['date_until'])
                        ? __('vault.file_table_filter_date_until').': '.($data['date_until'] ?? '')
                        : null
                    ),
            ], layout: FiltersLayout::AboveContentCollapsible);
        }

        return $table;
    }

    public function generateColumns(): array
    {
        $columns = [];

        if (! session('has_header')) {
            return [];
        }

        $headers = explode('|', session('headers'));
        $columnKeys = explode('|', session('column_keys', session('headers')));

        $last = session('columns') - 1;
        foreach ($headers as $i => $header) {
            $key = $columnKeys[$i] ?? $header;

            if (blank($key)) {
                continue;
            }

            $column = TextColumn::make($key)
                ->sortable()
                ->searchable()
                ->label($header)
                ->wrap()
                ->lineClamp(5)
                ->toggleable(isToggledHiddenByDefault: false);

            if ($this->ini !== null && $key == 'date') {
                $column->formatStateUsing(function ($state) {
                    if (! $state) {
                        return null;
                    }

                    $year = now()->year;

                    $state = trim(preg_replace('/\s+/', ' ', $state));
                    $line = sprintf('%s %s', $state, $year);
                    $dt = Carbon::parse($line, $this->tz);

                    return $dt->format('Y-m-d');
                });
            }

            switch ($last) {
                case 2:
                case 3:
                case 4:
                case 5:
                case 6:
                case 7:
                    $small = 'min-w-32';
                    $large = 'min-w-40';
                    if ($this->ini !== null) {
                        $small = 'min-w-20';
                        $large = 'min-w-40';
                    }
                    break;
                case 8:
                case 9:
                    $small = 'min-w-20';
                    $large = 'min-w-32';
                    break;
                default:
                    $small = 'min-w-20';
                    $large = 'min-w-32';
                    break;
            }

            if ($i < $last) {
                $column->extraAttributes(['class' => $small]);
            } else {
                $column->extraAttributes(['class' => $large]);
            }

            $columns[] = $column;
        }

        return $columns;
    }

    public function initializePage()
    {
        $this->dispatch('initializeFileTable');
    }
}

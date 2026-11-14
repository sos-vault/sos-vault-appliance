<?php

use App\Models\SupportCase;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Volt\Component;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;

middleware('auth');
name('fleet');

new class extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms, InteractsWithTable;

    public string $tableTitle = '';

    public string $description = '';

    public function mount(): void
    {
        $this->tableTitle = __('fleet.table_title');
        $this->description = __('fleet.table_description');
    }

    public function table(Table $table): Table
    {
        $gid = auth()->user()->group_id ?? auth()->id();

        $uid = auth()->id();

        return $table
            ->query(SupportCase::fleetQuery($gid, $uid))
            ->columns([
                TextColumn::make('display_hostname')
                    ->label(__('fleet.col_hostname'))
                    ->color('primary')
                    ->sortable()
                    ->searchable(['hostname', 'host'])
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('machine_id')
                    ->label(__('fleet.col_machine_id'))
                    ->placeholder(__('fleet.machine_id_unknown'))
                    ->copyable()
                    ->size(TextSize::Small)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('os_version')
                    ->icon(fn ($record): string => $record->os_icon ?? '')
                    ->label(__('fleet.col_os'))
                    ->tooltip(fn ($state): string => $state ?? '')
                    ->formatStateUsing(function (string $state): string {
                        $temp = explode(' ', $state);
                        $small = array_slice($temp, 0, 2);

                        return implode(' ', $small).' ...';
                    })
                    ->size(TextSize::Small)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('report_count')
                    ->label(__('fleet.col_reports'))
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('first_seen')
                    ->label(__('fleet.col_first_seen'))
                    ->dateTime('d/M/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('last_seen')
                    ->label(__('fleet.col_last_seen'))
                    ->dateTime('d/M/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('last_seen', 'desc')
            ->emptyStateHeading(__('fleet.empty_heading'))
            ->emptyStateDescription(__('fleet.empty_description'))
            ->emptyStateIcon('phosphor-empty-duotone')
            ->striped(true)
            ->deferColumnManager(false)
            ->reorderableColumns()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->paginated(true)
            ->recordUrl(fn ($record): string => '/fleet/'.urlencode($record->fleet_key));
    }
}
?>

<x-layouts.app>
    @volt('fleet')
        <x-app.container>

            <x-filament::section :description="$description" :heading="$tableTitle" :contained="false"
                icon="phosphor-computer-tower-duotone" icon-color="primary" icon-size="lg"
            >

                <div class="overflow-x-auto border rounded-lg">
                    {{ $this->table}}
                </div>

            </x-filament::section>

        </x-app.container>
    @endvolt
</x-layouts.app>

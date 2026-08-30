<?php

use App\Models\SupportCase;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
name('fleet.host');

new class extends Component implements HasActions, HasForms, HasTable
{
    use InteractsWithActions;
    use InteractsWithForms, InteractsWithTable;

    public string $fleetKey = '';

    public string $tableTitle = '';

    public string $description = '';

    public function mount(string $fleetKey): void
    {
        $this->fleetKey = $fleetKey;

        $gid = auth()->user()->group_id ?? auth()->id();
        $uid = auth()->id();

        $latest = SupportCase::fleetHostQuery($this->fleetKey, $gid, $uid)
            ->orderByDesc('date')
            ->first();

        $hostname = $latest?->hostname ?: $latest?->host ?: $this->fleetKey;
        $this->tableTitle = __('fleet.host_title', ['host' => $hostname]);

        // When the key is a machine-id show it; otherwise the reports carry no
        // /etc/machine-id and are grouped by the filename-derived host.
        $this->description = ($latest && $latest->machine_id === $this->fleetKey)
            ? __('fleet.host_description_machine_id', ['machine_id' => $this->fleetKey])
            : __('fleet.host_description_no_machine_id');
    }

    public function table(Table $table): Table
    {
        $gid = auth()->user()->group_id ?? auth()->id();

        $uid = auth()->id();

        return $table
            ->query(SupportCase::fleetHostQuery($this->fleetKey, $gid, $uid))
            ->columns([
                TextColumn::make('date')
                    ->label(__('fleet.col_date'))
                    ->dateTime('d/M/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('case')
                    ->label(__('fleet.col_case'))
                    ->color('primary')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('label')
                    ->label(__('fleet.col_label'))
                    ->sortable()
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
                TextColumn::make('sos_version')
                    ->label(__('fleet.col_sos_version'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('fleet.col_status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'OPEN' => 'primary',
                        'WAITCUST' => 'info',
                        'CLOSED' => 'danger',
                        'REOPEN' => 'primary',
                        'BLOCKED' => 'warning',
                        'SOLVED' => 'danger',
                        'DONE' => 'gray',
                        'WAIT' => 'info',
                        default => 'gray',
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('sha256')
                    ->label(__('fleet.col_sha256'))
                    ->copyable()
                    ->size(TextSize::Small)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date', 'asc')
            ->emptyStateHeading(__('fleet.host_empty_heading'))
            ->emptyStateDescription(__('fleet.host_empty_description'))
            ->emptyStateIcon('phosphor-empty-duotone')
            ->striped(true)
            ->deferColumnManager(false)
            ->reorderableColumns()
            ->persistSearchInSession()
            ->persistColumnSearchesInSession()
            ->paginated(true)
            ->recordActions([
                ActionGroup::make([
                    Action::make('browse')
                        ->label(__('fleet.action_browse'))
                        ->icon('phosphor-binoculars-duotone')
                        ->url(fn ($record): string => '/sosbrowser/'.$record->id),
                    Action::make('summary')
                        ->label(__('fleet.action_summary'))
                        ->icon('phosphor-chart-donut-duotone')
                        ->url(fn ($record): string => "/sosTool/{$record->vault_id}/{$record->file_id}/Summary/{$record->id}"),
                    Action::make('compare')
                        ->label(__('fleet.action_compare'))
                        ->icon('phosphor-git-diff-duotone')
                        ->url(fn ($record): string => "/sosTool/{$record->vault_id}/{$record->file_id}/Compare/{$record->id}"),
                ]),
            ]);
    }
}
?>

<x-layouts.app>
    @volt('fleet.host')
        <x-app.container>

            <a href="/fleet" class="inline-flex items-center gap-1 mb-4 text-sm text-primary-600 hover:underline" wire:navigate>
                <x-phosphor-caret-left-duotone class="w-4 h-4" />
                {{ __('fleet.back_to_fleet') }}
            </a>

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

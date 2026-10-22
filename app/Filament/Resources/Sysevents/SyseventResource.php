<?php

namespace App\Filament\Resources\Sysevents;

use App\Filament\Resources\Sysevents\Pages\ListSysevents;
use App\Jobs\ForwardEventToSiem;
use App\Models\Sysevent;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SyseventResource extends Resource
{
    protected static ?string $model = Sysevent::class;

    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-pulse-duotone';

    protected static ?string $navigationLabel = 'Event Log';

    protected static ?string $pluralModelLabel = 'Events';

    protected static ?string $modelLabel = 'Event';

    protected static ?int $navigationSort = 10;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    /**
     * Open-core gate: the Event Log is a licensed-tier feature on appliance
     * builds. SaaS keeps it visible to operators.
     */
    public static function canAccess(): bool
    {
        return isSaas() || applianceLicensed();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('ownerUser')->latest())
            ->deferColumnManager(false)
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('type')
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'SUCCESS' => 'success',
                        'FAILED' => 'danger',
                        'WARNING' => 'warning',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('class')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('ownerUser.name')
                    ->label('User')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('vault_id')
                    ->label('Vault')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('case_id')
                    ->label('Case')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('ip')
                    ->label('IP')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('country')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('city')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(fn () => Sysevent::query()
                        ->distinct()
                        ->orderBy('type')
                        ->pluck('type', 'type')
                        ->toArray()
                    )
                    ->searchable(),

                SelectFilter::make('status')
                    ->options([
                        'SUCCESS' => 'Success',
                        'FAILED' => 'Failed',
                        'WARNING' => 'Warning',
                    ]),

                SelectFilter::make('class')
                    ->options(fn () => Sysevent::query()
                        ->distinct()
                        ->orderBy('class')
                        ->pluck('class', 'class')
                        ->toArray()
                    ),

                SelectFilter::make('owner')
                    ->label('User')
                    ->options(fn () => User::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->toArray()
                    )
                    ->searchable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->modalWidth('4xl')
                        ->modalHeading(fn (Sysevent $record) => "Event #{$record->id} — {$record->type}")
                        ->schema(fn (Sysevent $record): array => [
                            Grid::make(4)->schema([
                                TextEntry::make('type')
                                    ->badge(),

                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn (string $state): string => match ($state) {
                                        'SUCCESS' => 'success',
                                        'FAILED' => 'danger',
                                        'WARNING' => 'warning',
                                        default => 'gray',
                                    }),

                                TextEntry::make('class')
                                    ->badge()
                                    ->color('gray'),

                                TextEntry::make('created_at')
                                    ->label('When')
                                    ->dateTime('Y-m-d H:i:s'),

                                TextEntry::make('ownerUser.name')
                                    ->label('User')
                                    ->default('—'),

                                TextEntry::make('ownerUser.email')
                                    ->label('Email')
                                    ->default('—'),

                                TextEntry::make('vault_id')
                                    ->label('Vault')
                                    ->default('—'),

                                TextEntry::make('case_id')
                                    ->label('Case')
                                    ->default('—'),

                                TextEntry::make('ip')
                                    ->label('IP')
                                    ->default('—'),

                                TextEntry::make('location')
                                    ->label('Location')
                                    ->state(fn (Sysevent $record): string => implode(', ', array_filter([$record->city, $record->country])) ?: '—'),
                            ]),

                            Section::make('Payload')
                                ->schema([
                                    TextEntry::make('payload')
                                        ->label('')
                                        ->html()
                                        ->state(fn (Sysevent $record): string => $record->payload
                                            ? '<pre class="text-xs font-mono whitespace-pre-wrap break-all">'.e(json_encode(json_decode($record->payload, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'</pre>'
                                            : '<span class="text-gray-400">—</span>'
                                        )
                                        ->columnSpanFull(),
                                ])
                                ->columnSpanFull()
                                ->collapsible(),
                        ]),

                    Action::make('sendToSiem')
                        ->label('Send to SIEM')
                        ->icon('phosphor-broadcast-duotone')
                        ->visible(fn (ListSysevents $livewire): bool => $livewire->siemEnabled())
                        ->modalHeading(fn (Sysevent $record): string => "Forward event #{$record->id} to SIEM")
                        ->modalDescription('Re-sends this exact event to the configured SIEM and traces the delivery.')
                        ->modalContent(fn (Sysevent $record, ListSysevents $livewire) => view('filament.siem-test-result', [
                            'result' => $livewire->siemForwardResult($record),
                        ]))
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Close'),

                    DeleteAction::make(),
                ]),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('sendToSiem')
                        ->label('Send to SIEM')
                        ->icon('phosphor-broadcast-duotone')
                        ->visible(fn (ListSysevents $livewire): bool => $livewire->siemEnabled())
                        ->requiresConfirmation()
                        ->modalHeading('Forward selected events to SIEM')
                        ->modalDescription('Re-sends every selected event to the configured SIEM. Delivery runs on the queue, so a slow or unreachable SIEM never blocks this page.')
                        ->action(function (Collection $records): void {
                            $records->each(fn (Sysevent $record) => ForwardEventToSiem::dispatch($record));

                            Notification::make()
                                ->success()
                                ->title('Queued '.$records->count().' event(s) for the SIEM')
                                ->body('Check the Event Log or your SIEM to confirm delivery.')
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->poll('10s')
            ->defaultPaginationPageOption(50)
            ->persistSortInSession();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSysevents::route('/'),
        ];
    }
}

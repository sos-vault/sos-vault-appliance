<?php

namespace App\Livewire;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Wave\Subscription;

class Subscriptions extends TableWidget
{
    protected array|string|int $columnSpan = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(Subscription::where('billable_id', auth()->user()->id)->where('billable_type', 'user'))
            ->emptyStateDescription(__('settings.sub_empty_description'))
            ->emptyStateHeading(__('settings.sub_empty_heading'))
            ->emptyStateIcon('phosphor-empty-duotone')
            ->queryStringIdentifier('subscriptions')
            ->paginated(true)
            ->defaultPaginationPageOption(5)
            ->striped(true)
            ->reorderableColumns()
            ->heading(__('settings.sub_heading'))
            ->defaultSort('created_at', direction: 'desc')
            ->columns([
                TextColumn::make('plan.name')
                    ->label(__('settings.sub_col_plan'))
                    ->size('xs')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('plan.description')
                    ->label(__('settings.sub_col_description'))
                    ->size('xs')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('settings.sub_col_since'))
                    ->size('xs')
                    ->date('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('cycle')
                    ->label(__('settings.sub_col_cycle'))
                    ->size('xs')
                    ->formatStateUsing(fn (string $state): string => $state === 'year' ? __('plan.badge_yearly') : __('plan.badge_monthly'))
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('ends_at')
                    ->label(__('settings.sub_col_renews'))
                    ->size('xs')
                    ->date('M j, Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('plan.price')
                    ->label(__('settings.sub_col_price'))
                    ->size('xs')
                    ->money('usd')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('status')
                    ->label(__('settings.sub_col_status'))
                    ->size('xs')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ]);
    }
}

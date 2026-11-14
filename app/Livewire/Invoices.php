<?php

namespace App\Livewire;

use App\Models\Invoice;
use Carbon\Carbon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class Invoices extends TableWidget
{
    protected array|string|int $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(Invoice::query())
            ->emptyStateDescription(__('settings.invoices_empty_description'))
            ->emptyStateHeading(__('settings.invoices_empty_heading'))
            ->emptyStateIcon('phosphor-empty-duotone')
            ->queryStringIdentifier('invoices')
            ->paginated(true)
            ->defaultPaginationPageOption(5)
            ->striped(true)
            ->reorderableColumns()
            ->heading(__('settings.invoices_widget_heading'))
            ->defaultSort('created_at', direction: 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('settings.invoices_col_date'))
                    ->size('xs')
                    ->date('M j, Y')
                    ->description(function ($record): string {
                        $ini = Carbon::parse($record['billing_start'])->toFormattedDateString();
                        $end = Carbon::parse($record['billing_end'])->toFormattedDateString();

                        return sprintf('bill period: %s - %s', $ini, $end);
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('billing_period')
                    ->size('xs')
                    ->label(__('settings.invoices_col_period'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('price')
                    ->size('xs')
                    ->label(__('settings.invoices_col_price'))
                    ->money(fn ($record): string => $record['currency_code'])
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('total')
                    ->size('xs')
                    ->label(__('settings.invoices_col_paid'))
                    ->color('primary')
                    ->money(fn ($record): string => $record['currency_code'])
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('invoice_number')
                    ->size('xs')
                    ->label(__('settings.invoices_col_invoice'))
                    ->url(fn ($record): string => '/settings/invoices/'.$record['id'])
                    ->openUrlInNewTab()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('name')
                    ->size('xs')
                    ->label(__('settings.invoices_col_items'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('description')
                    ->size('xs')
                    ->label(__('settings.invoices_col_items_desc'))
                    ->wrap()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->size('xs')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'primary',
                        default => 'gray',
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('card')
                    ->size('xs')
                    ->label(__('settings.invoices_col_card'))
                    ->wrap()
                    ->lineClamp(2)
                    ->listWithLineBreaks()
                    ->state(function ($record): array {
                        $out = [];
                        $expiry = sprintf('%02d/%s', $record['expiry_month'], $record['expiry_year']);
                        $out[] = sprintf('%s: **%s', strtoupper($record['card']), $record['last4']);
                        $out[] = sprintf('valid: %s', $expiry);

                        return $out;
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}

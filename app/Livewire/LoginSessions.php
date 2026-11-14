<?php

namespace App\Livewire;

use App\Models\LoginSession;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LoginSessions extends TableWidget
{
    protected array|string|int $columnSpan = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(LoginSession::where('type', 'LOGOUT'))
            ->emptyStateDescription(__('settings.sessions_empty_description'))
            ->emptyStateHeading(__('settings.sessions_empty_heading'))
            ->emptyStateIcon('phosphor-empty-duotone')
            ->queryStringIdentifier('events')
            ->paginated(true)
            ->defaultPaginationPageOption(5)
            ->striped(true)
            ->reorderableColumns()
            ->persistSortInSession()
            ->heading(__('settings.sessions_heading'))
            ->defaultSort('date', direction: 'desc')
            ->columns([
                TextColumn::make('date')
                    ->label(__('settings.sessions_col_session'))
                    ->size('xs')
                    ->date('Y-m-d H:m:s')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('duration')
                    ->sortable()
                    ->size('xs')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('via')
                    ->label(__('settings.sessions_col_type'))
                    ->size('xs')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('ip')
                    ->label(__('settings.sessions_col_ip'))
                    ->size('xs')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('location')
                    ->sortable()
                    ->size('xs')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('status')
                    ->badge()
                    ->size('xs')
                    ->color(fn (string $state): string => match ($state) {
                        'SUCCSESS' => 'primary',
                        'VALID' => 'primary',
                        'LOCKED' => 'warning',
                        'INVALID' => 'danger',
                        'EXPIRED' => 'warning',
                        'DELETED' => 'info',
                        'FAIL' => 'danger',
                        'FAILED' => 'danger',
                        default => 'gray',
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')
                    ->size('xs')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('message')
                    ->size('xs')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')
                    ->size('xs')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}

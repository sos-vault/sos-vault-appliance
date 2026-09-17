<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RedirectResource\Pages;
use App\Models\Redirect;
use BackedEnum;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class RedirectResource extends Resource
{
    protected static ?string $model = Redirect::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTopRightOnSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Redirects';

    public static function canAccess(): bool
    {
        return isSaas();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('from_path')
                    ->label('From path')
                    ->required()
                    ->placeholder('/blog/old-slug')
                    ->helperText('Must start with /'),
                TextInput::make('to_path')
                    ->label('To path')
                    ->required()
                    ->placeholder('/blog/new-slug')
                    ->helperText('Can be a path or full URL'),
                Select::make('status_code')
                    ->label('Redirect type')
                    ->options([
                        301 => '301 — Permanent (SEO-safe)',
                        302 => '302 — Temporary',
                    ])
                    ->default(301)
                    ->required(),
                Toggle::make('active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_path')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('to_path')
                    ->searchable(),
                TextColumn::make('status_code')
                    ->label('Type')
                    ->badge()
                    ->color(fn (int $state): string => $state === 301 ? 'success' : 'warning'),
                IconColumn::make('active')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([])
            ->recordActions([
                Actions\EditAction::make()
                    ->after(fn () => Cache::forget('url_redirects')),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make()
                        ->after(fn () => Cache::forget('url_redirects')),
                ]),
            ])
            ->defaultPaginationPageOption(50)
            ->persistSortInSession();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRedirects::route('/'),
            'create' => Pages\CreateRedirect::route('/create'),
            'edit' => Pages\EditRedirect::route('/{record}/edit'),
        ];
    }
}

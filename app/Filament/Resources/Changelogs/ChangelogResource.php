<?php

namespace App\Filament\Resources\Changelogs;

use App\Filament\Resources\Changelogs\Pages\CreateChangelog;
use App\Filament\Resources\Changelogs\Pages\EditChangelog;
use App\Filament\Resources\Changelogs\Pages\ListChangelogs;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Wave\Changelog;

class ChangelogResource extends Resource
{
    protected static ?string $model = Changelog::class;

    protected static string|\BackedEnum|null $navigationIcon = 'phosphor-book-open-text-duotone';

    protected static ?int $navigationSort = 7;

    /**
     * The changelog ships as fixed product content (release notes). The customer
     * admin must not manage it on the self-hosted appliance, so the whole
     * resource is hidden there — canViewAny() gates both the navigation item
     * (via canAccess()) and direct page access. Users still read the public
     * /changelog page. The write gates below keep SaaS fully manageable and
     * provide defence-in-depth.
     */
    public static function canViewAny(): bool
    {
        return ! isAppliance();
    }

    public static function canCreate(): bool
    {
        return ! isAppliance();
    }

    public static function canEdit(Model $record): bool
    {
        return ! isAppliance();
    }

    public static function canDelete(Model $record): bool
    {
        return ! isAppliance();
    }

    public static function canDeleteAny(): bool
    {
        return ! isAppliance();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(191),
                TextInput::make('description')
                    ->required()
                    ->maxLength(191),
                RichEditor::make('body')
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('description')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListChangelogs::route('/'),
            'create' => CreateChangelog::route('/create'),
            'edit' => EditChangelog::route('/{record}/edit'),
        ];
    }
}

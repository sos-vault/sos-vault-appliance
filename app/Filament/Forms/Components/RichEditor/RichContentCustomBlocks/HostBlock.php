<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

class HostBlock extends SosCustomBlock
{

    public static function getId(): string
    {
        return 'host';
    }

    public static function getLabel(): string
    {
        return 'Host Info Table';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalDescription('Configure the Host info table')
            ->schema([
                TextInput::make('heading')
                    ->default('Host info')
                    ->label('Table title')
                    ->required(),
                TextInput::make('subheading')
                    ->default('Host system general info')
                    ->label('Description')
            ]);
    }

    public static function toPreviewHtml(array $config): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.generic.preview', [
            'heading'    => $config['heading'],
            'subheading' => $config['subheading'],
        ])->render();
    }
}

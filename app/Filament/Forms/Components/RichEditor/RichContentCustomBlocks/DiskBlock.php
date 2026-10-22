<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

class DiskBlock extends SosCustomBlock
{

    public static function getId(): string
    {
        return 'disk';
    }

    public static function getLabel(): string
    {
        return 'Disk Table';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalDescription('Configure the Disk info table')
            ->schema([
                TextInput::make('heading')
                    ->default('Disk info')
                    ->label('Table title')
                    ->required(),
                TextInput::make('subheading')
                    ->default('System Disk percentage usage')
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

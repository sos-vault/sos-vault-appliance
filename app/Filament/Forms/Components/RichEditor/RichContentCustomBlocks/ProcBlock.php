<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

class ProcBlock extends SosCustomBlock
{

    public static function getId(): string
    {
        return 'proc';
    }

    public static function getLabel(): string
    {
        return 'Single Process Table';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalDescription('Configure the Single Process info table')
            ->schema([
                TextInput::make('heading')
                    ->default('Single Process info')
                    ->label('Table title')
                    ->required(),
                TextInput::make('subheading')
                    ->default('Process data for PID')
                    ->label('Description'),
                TextInput::make('pid')
                    ->placeholder('Process ID')
                    ->numeric()
                    ->label('Process ID')
                    ->required(),
            ]);
    }

    public static function toPreviewHtml(array $config): string
    {
        return view('filament.forms.components.rich-editor.rich-content-custom-blocks.generic.preview', [
            'heading'    => $config['heading'],
            'subheading' => $config['subheading'],
            'pid'        => $config['pid'],
        ])->render();
    }
}

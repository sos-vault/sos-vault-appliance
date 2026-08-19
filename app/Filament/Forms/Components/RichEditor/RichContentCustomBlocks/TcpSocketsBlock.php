<?php

namespace App\Filament\Forms\Components\RichEditor\RichContentCustomBlocks;

use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

class TcpSocketsBlock extends SosCustomBlock
{

    public static function getId(): string
    {
        return 'tcpsockets';
    }

    public static function getLabel(): string
    {
        return 'TCP sockets Table';
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalDescription('Configure the TCP sockets info table')
            ->schema([
                TextInput::make('heading')
                    ->default('TCP sockets info')
                    ->label('Table title')
                    ->required(),
                TextInput::make('subheading')
                    ->default('System TCP sockets percentage usage')
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

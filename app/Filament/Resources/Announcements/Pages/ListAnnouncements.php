<?php

namespace App\Filament\Resources\Announcements\Pages;

use Filament\Actions\CreateAction;
use App\Filament\Resources\Announcements\AnnouncementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAnnouncements extends ListRecords
{
    protected static string $resource = AnnouncementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

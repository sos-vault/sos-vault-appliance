<?php

namespace App\Filament\Resources\RedirectResource\Pages;

use App\Filament\Resources\RedirectResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Cache;

class CreateRedirect extends CreateRecord
{
    protected static string $resource = RedirectResource::class;

    protected function afterCreate(): void
    {
        Cache::forget('url_redirects');
    }
}

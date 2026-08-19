<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Open-core gate (defense-in-depth for direct URL access): user creation
     * requires SaaS or an actively-licensed appliance. UserResource::canCreate()
     * hides the "New user" button; this guards the /admin/users/create route
     * itself. On an unlicensed or expired appliance the Users list stays
     * visible read-only — see UserResource::canManageUsers().
     */
    public static function canAccess(array $parameters = []): bool
    {
        return UserResource::canManageUsers();
    }
}

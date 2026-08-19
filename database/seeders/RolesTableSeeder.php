<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Wave\Setting;

class RolesTableSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'admin',      'display_name' => 'Admin User'],
            ['name' => 'Minimal',    'display_name' => 'Minimal Service'],
            ['name' => 'Basic',      'display_name' => 'Basic Plan'],
            ['name' => 'Team',       'display_name' => 'Team Plan'],
            ['name' => 'Enterprise', 'display_name' => 'Enterprise Plan'],
            ['name' => 'cancelled',  'display_name' => 'Cancelled User'],
            ['name' => 'Free',       'display_name' => 'Free Trial'],
            ['name' => 'suspended',  'display_name' => 'Suspended User'],
            ['name' => 'Self-hosted', 'display_name' => 'Self-Hosted Customer'],
            ['name' => 'Team Member', 'display_name' => 'Team Member'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(
                ['name' => $role['name'], 'guard_name' => 'web'],
                ['display_name' => $role['display_name']]
            );
        }

        // Default role for new registrations. SaaS = 'Free' trial; the
        // appliance has no trial concept, so new users land on the
        // 'Team Member' role and the admin assigns them to a group.
        $defaultRole = isAppliance() ? 'Team Member' : 'Free';

        Setting::updateOrCreate(
            ['key' => 'auth.default_role'],
            ['display_name' => 'Default Role', 'value' => $defaultRole, 'type' => 'text', 'order' => 0]
        );
    }
}

<?php

use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Sprint 5 Step D — seed the default team (Group) on appliance installs.
 *
 * Decision (HANDOFF §4.5c): default team is created via migration, not a
 * separate installer step. The migration is conditional on:
 *   - product.type === 'appliance'
 *   - at least one User row exists
 *   - no Group row exists yet
 *
 * The canonical first-boot path is now Database\Seeders\ApplianceAdminSeeder
 * (Sprint 6 Step A), which creates the admin user AND the default team in
 * one shot — and is what the installer invokes after `php artisan migrate`.
 * This migration remains as a fallback safety net for installs that were
 * created before the seeder existed, or where users were imported into a
 * fresh schema before the team was wired up. It is a no-op on the SaaS
 * branch and on appliance installs that already have a Group.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('product.type') !== 'appliance') {
            return;
        }

        if (Group::query()->exists()) {
            return;
        }

        $owner = User::query()->orderBy('id')->first();
        if (! $owner) {
            // Fresh install before the admin user is seeded. Sprint 7
            // installer re-invokes this migration after seeding the admin
            // user; until then there's nothing to anchor the team to.
            return;
        }

        $seats = optional(LocalLicense::current())->seats ?? 8;

        Group::create([
            'name' => 'Default Team',
            'owner_id' => $owner->id,
            'max_members' => max(2, (int) $seats),
        ]);
    }

    public function down(): void
    {
        // No-op: tearing down a default team would orphan vault rows
        // that may have been created against it. Operators who truly
        // want to remove the team use the Filament Groups admin.
    }
};

<?php

namespace Database\Seeders;

use App\Models\LocalLicense;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Dev-only convenience: install a fully-featured LocalLicense so the licensed
 * appliance surface (Groups, multi-user, encrypted vaults, modules, event log,
 * Disk/NAS) is reachable locally without running the real verification-report
 * + Paddle + GPG flow.
 *
 * The row is NOT GPG-signed and carries a placeholder machine token, so it must
 * never reach production — signature + machine-token verification happen at
 * install time (LocalLicenseService::install()), which this bypasses. The
 * seeder therefore refuses to run on the production environment and only runs
 * on an appliance build.
 *
 * Invocation:
 *   php artisan db:seed --class='Database\Seeders\DevApplianceLicenseSeeder'
 *
 * Idempotent: no-ops when an active (non-expired) license is already installed.
 */
class DevApplianceLicenseSeeder extends Seeder
{
    public function run(): void
    {
        if (! isAppliance()) {
            throw new RuntimeException('DevApplianceLicenseSeeder only runs on appliance installs (config product.type=appliance).');
        }

        if (app()->environment('production')) {
            throw new RuntimeException('DevApplianceLicenseSeeder must never run on production — it installs an unsigned, machine-unbound license. Upload a real .lic via Manage License instead.');
        }

        if (LocalLicense::current() !== null) {
            $this->command?->info('DevApplianceLicenseSeeder: an active license already exists — nothing to do.');

            return;
        }

        $license = LocalLicense::create([
            'uuid' => (string) Str::uuid(),
            'customer_id' => 1,
            'machine_tokens' => ['dev-machine-token'],
            'seats' => 25,
            'features' => ['srms', 'ai_analysis', 'jira', 'telegram'],
            'status' => 'ACTIVE',
            'signed_license' => 'DEV-LICENSE — locally seeded for testing, not GPG-signed',
            'issued_at' => now(),
            'expires_at' => now()->addYear(),
            'uploaded_by' => null,
        ]);

        $this->command?->info("DevApplianceLicenseSeeder: installed dev license {$license->uuid} (25 seats, expires {$license->expires_at->toDateString()}).");
    }
}

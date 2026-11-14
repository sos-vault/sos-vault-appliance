<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\LocalLicense;
use App\Models\User;
use App\Services\ApplianceNetworkSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Wave\Setting;

/**
 * Sprint 6 Step A — first-boot admin user + default team for the appliance.
 *
 * Resolves the chicken-and-egg called out in HANDOFF §1.13 / §4.6:
 *   1. groups.owner_id is NOT NULL, so the default-team migration
 *      (2026_04_29_160741_seed_default_team_on_appliance.php) refuses
 *      to create a team until at least one User row exists.
 *   2. User::creating() refuses new rows on appliance when no LocalLicense
 *      is installed — but the operator hasn't uploaded a .lic yet.
 *
 * The installer (Sprint 6 Step D) runs `php artisan migrate` (which lays
 * down the schema and no-ops the default-team migration), then calls this
 * seeder explicitly. The seeder bypasses the User::creating() seat guard
 * by INSERTing the row through the query builder, then attaches the admin
 * role and creates the default team in one go.
 *
 * Invocation:
 *   INSTALLER_ADMIN_EMAIL=admin@example.com \
 *   INSTALLER_ADMIN_PASSWORD='hunter2' \
 *   php artisan db:seed --class='Database\Seeders\ApplianceAdminSeeder'
 *
 * Optional env: INSTALLER_ADMIN_NAME (default 'Administrator'),
 *               INSTALLER_ADMIN_USERNAME (default = slug of email local-part).
 *
 * Idempotent: re-running with the same email finds the existing user and
 * only fixes up the admin role + default team if either is missing.
 */
class ApplianceAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! isAppliance()) {
            throw new RuntimeException('ApplianceAdminSeeder must only be run on appliance installs (config product.type=appliance).');
        }

        $email = (string) (getenv('INSTALLER_ADMIN_EMAIL') ?: '');
        $password = (string) (getenv('INSTALLER_ADMIN_PASSWORD') ?: '');
        $name = (string) (getenv('INSTALLER_ADMIN_NAME') ?: 'Administrator');

        if ($email === '' || $password === '') {
            throw new RuntimeException('ApplianceAdminSeeder requires INSTALLER_ADMIN_EMAIL and INSTALLER_ADMIN_PASSWORD env vars.');
        }

        $username = (string) (getenv('INSTALLER_ADMIN_USERNAME') ?: Str::slug(Str::before($email, '@'), ''));
        if ($username === '') {
            $username = 'admin';
        }

        // A freshly-installed appliance starts from an empty database (the deb
        // no longer ships the dev DB), so the framework's bootstrap tables are
        // empty. Seed the minimal, PII-free baseline the appliance needs:
        //   - RolesTableSeeder: the spatie roles (else syncRoles('admin') below
        //     throws RoleDoesNotExist) + the appliance auth.default_role setting.
        //     Idempotent (firstOrCreate).
        //   - ThemesTableSeeder: the active 'anchor' theme row. Without it the
        //     themes table is empty, DevDojo\Themes never registers the `theme::`
        //     view namespace, and the login page throws "No hint path defined
        //     for [theme]". Inserts only the shipped Anchor theme (no PII).
        //   - ChangelogsTableSeeder: the product release notes shown on the
        //     /changelog page (users can view it; admin write access is blocked
        //     on the appliance — see ChangelogResource).
        $this->call(RolesTableSeeder::class);
        $this->call(ThemesTableSeeder::class);
        $this->call(ChangelogsTableSeeder::class);

        // Mirror the installer-configured host/HTTPS port (from APP_URL) into the
        // settings table so the admin "Manage Settings" page shows real values.
        $this->seedNetworkSettings();

        // Seed the AI Assistant defaults (provider=local, the bundled llama.cpp
        // URL/model, cloud model names, tunables) so the "Manage Settings" AI
        // section shows real values instead of blank fields on a fresh install.
        // firstOrCreate — never overwrites a value the operator later edits.
        $this->call(AiSettingsSeeder::class);

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            // Bypass User::creating() — that hook reads LocalLicense::current()
            // and the operator hasn't uploaded a .lic yet on first boot.
            $now = now();
            DB::table('users')->insert([
                'name' => $name,
                'email' => $email,
                'username' => $this->uniqueUsername($username),
                'password' => Hash::make($password),
                'verified' => 1,
                'email_verified_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $user = User::query()->where('email', $email)->firstOrFail();
        }

        if (! $user->hasRole('admin')) {
            $user->syncRoles(['admin']);
        }

        if (! Group::query()->exists()) {
            $seats = optional(LocalLicense::current())->seats ?? 8;
            Group::create([
                'name' => 'Default Team',
                'owner_id' => $user->id,
                'max_members' => max(2, (int) $seats),
            ]);
        }

        // Seed the product documentation (sos command / sos-vault / self-hosted
        // guides served at /blog/*). Runs last so the posts' author resolves to
        // the admin just created. Idempotent (upsert by slug).
        $this->call(ApplianceDocsSeeder::class);
    }

    /**
     * Seed appliance.host / appliance.port from the configured APP_URL (set by
     * the installer at Step 7b), falling back to the OS hostname / default port.
     * Only creates rows that are absent so a value an operator later edits via
     * Manage Settings survives a re-seed.
     */
    private function seedNetworkSettings(): void
    {
        $url = (string) config('app.url');
        $host = parse_url($url, PHP_URL_HOST) ?: ApplianceNetworkSettings::osHostname();
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ApplianceNetworkSettings::DEFAULT_PORT);

        $defaults = [
            ApplianceNetworkSettings::HOST_KEY => $host,
            ApplianceNetworkSettings::PORT_KEY => (string) $port,
        ];

        foreach ($defaults as $key => $value) {
            if (! Setting::where('key', $key)->exists()) {
                Setting::create([
                    'key' => $key,
                    'display_name' => $key,
                    'value' => $value,
                    'type' => 'text',
                    'order' => 0,
                ]);
            }
        }
    }

    private function uniqueUsername(string $candidate): string
    {
        $username = $candidate;
        $i = 1;
        while (User::query()->where('username', $username)->exists()) {
            $username = $candidate.$i;
            $i++;
        }

        return $username;
    }
}

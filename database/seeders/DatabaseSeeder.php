<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->call(RolesTableSeeder::class);
        $this->call(UsersTableSeeder::class);
        $this->call(ChangelogsTableSeeder::class);
        $this->call(ApiKeysTableSeeder::class);
        $this->call(CategoriesTableSeeder::class);
        $this->call(NotificationsTableSeeder::class);
        $this->call(PasswordResetsTableSeeder::class);
        $this->call(PermissionsTableSeeder::class);
        $this->call(PermissionRoleTableSeeder::class);
        $this->call(ModelHasRolesTableSeeder::class);
        $this->call(PlansTableSeeder::class);
        $this->call(StandaloneDocsSeeder::class);
        $this->call(SettingsTableSeeder::class);
        $this->call(AiSettingsSeeder::class);
        $this->call(ProfileKeyValuesTableSeeder::class);
        $this->call(ThemesTableSeeder::class);
        fixPostgresSequence();
    }
}

if (! function_exists('fixPostgresSequence')) {

    function fixPostgresSequence()
    {
        if (config('database.default') === 'pgsql') {
            $tables = \DB::select('SELECT table_name FROM information_schema.tables WHERE table_schema = \'public\' ORDER BY table_name;');
            foreach ($tables as $table) {
                if (\Schema::hasColumn($table->table_name, 'id')) {
                    $columnType = \DB::select('SELECT data_type FROM information_schema.columns WHERE table_name = ? AND column_name = ?', [$table->table_name, 'id'])[0]->data_type;
                    // Only proceed if the 'id' column is numeric
                    if (in_array($columnType, ['integer', 'bigint', 'smallint', 'smallserial', 'serial', 'bigserial'])) {
                        $seq = \DB::table($table->table_name)->max('id') + 1;
                        // Bind the value args; the FROM identifier (trusted, from
                        // information_schema) is double-quoted rather than interpolated raw.
                        $quotedTable = '"'.str_replace('"', '""', $table->table_name).'"';
                        \DB::select('SELECT setval(pg_get_serial_sequence(?, ?), coalesce(?, 1), false) FROM '.$quotedTable, [$table->table_name, 'id', $seq]);
                    }
                }
            }
        }
    }
}

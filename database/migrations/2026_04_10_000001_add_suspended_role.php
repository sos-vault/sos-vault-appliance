<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('roles')->where('name', 'suspended')->exists()) {
            return;
        }

        DB::table('roles')->insert([
            'name' => 'suspended',
            'display_name' => 'Suspended',
            'description' => 'Account suspended due to a billing event (refund, chargeback, or cancellation).',
            'guard_name' => 'web',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'suspended')->delete();
    }
};

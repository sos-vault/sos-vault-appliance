<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->where('name->en', 'Extra seat')->update(['type' => 'seat']);
    }

    public function down(): void
    {
        DB::table('plans')->where('name->en', 'Extra seat')->update(['type' => 'service']);
    }
};

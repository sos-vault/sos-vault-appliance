<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('plans', 'plan_id')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->renameColumn('plan_id', 'price_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('plans', 'price_id')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->renameColumn('price_id', 'plan_id');
            });
        }
    }
};

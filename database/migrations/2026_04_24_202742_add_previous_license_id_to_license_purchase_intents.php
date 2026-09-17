<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_purchase_intents', function (Blueprint $table) {
            $table->foreignId('previous_license_id')
                ->nullable()
                ->after('license_id')
                ->constrained('licenses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('license_purchase_intents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('previous_license_id');
        });
    }
};

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
        Schema::table('support_cases', function (Blueprint $table) {
            $table->string('machine_id', 64)->nullable()->index()->after('host');
            $table->string('hostname')->nullable()->after('machine_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_cases', function (Blueprint $table) {
            $table->dropIndex(['machine_id']);
            $table->dropColumn(['machine_id', 'hostname']);
        });
    }
};

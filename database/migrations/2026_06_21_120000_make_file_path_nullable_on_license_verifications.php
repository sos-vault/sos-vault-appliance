<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verifications no longer come from an uploaded sosreport file — the operator
 * pastes a machine key generated on the appliance — so file_path is now
 * optional (null for key-based verifications). The column is retained for
 * legacy rows created by the former upload flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_verifications', function (Blueprint $table) {
            $table->string('file_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('license_verifications', function (Blueprint $table) {
            $table->string('file_path')->nullable(false)->change();
        });
    }
};

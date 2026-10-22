<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Open-core: deduplicate LICENSE_EXPIRED events.
 *
 * sos-vault:check-license-expiry runs daily; without a per-row timestamp it
 * would emit a fresh LICENSE_EXPIRED event every day for the same license.
 * The column is set the first time a license is observed expired, and the
 * command short-circuits on subsequent runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_licenses', function (Blueprint $table) {
            $table->timestamp('expiry_event_logged_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('local_licenses', function (Blueprint $table) {
            $table->dropColumn('expiry_event_logged_at');
        });
    }
};

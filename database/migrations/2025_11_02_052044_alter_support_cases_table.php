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
        //
        Schema::table('support_cases', function (Blueprint $table) {

            if (! Schema::hasColumn('support_cases', 'os_version')) {
                $table->string('os_version')
                    ->nullable();
            }

            if (! Schema::hasColumn('support_cases', 'sos_version')) {
                $table->string('sos_version')
                    ->nullable();
            }

            if (! Schema::hasColumn('support_cases', 'os_icon')) {
                $table->string('os_icon')
                    ->nullable();
            }

            if (! Schema::hasColumn('support_cases', 'sort')) {
                $table->string('sort')
                    ->nullable();
            }

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::table('support_cases', function (Blueprint $table) {
            $table->dropColumn([
                'os_version',
                'sos_version',
                'os_icon',
                'sort',
            ]);
        });
    }
};

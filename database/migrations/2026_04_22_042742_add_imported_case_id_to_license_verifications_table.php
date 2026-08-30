<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('license_verifications', function (Blueprint $table) {
            $table->foreignId('imported_case_id')
                ->nullable()
                ->after('requirements_check')
                ->constrained('support_cases')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('license_verifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('imported_case_id');
        });
    }
};

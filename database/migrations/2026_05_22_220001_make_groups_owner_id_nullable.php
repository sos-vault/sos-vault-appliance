<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Appliance group-vault sprint — groups created from the Filament
 * admin panel on the appliance have no "manager" user; the admin
 * provisions the group and assigns Team Member users. Make
 * groups.owner_id nullable. SaaS Team / Enterprise groups continue
 * to set owner_id on creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->change()
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable(false)->change()
                ->constrained('users')->cascadeOnDelete();
        });
    }
};

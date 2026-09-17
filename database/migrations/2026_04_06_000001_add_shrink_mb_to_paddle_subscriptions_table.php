<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paddle_subscriptions', function (Blueprint $table) {
            // MB to shrink when a plan downgrade is scheduled via switchPlan().
            // NULL = regular disk expansion record; non-null = plan-downgrade scheduled shrink.
            $table->unsignedInteger('shrink_mb')->nullable()->after('delete_at');
        });
    }

    public function down(): void
    {
        Schema::table('paddle_subscriptions', function (Blueprint $table) {
            $table->dropColumn('shrink_mb');
        });
    }
};

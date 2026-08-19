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
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'slug')) {
                $table->string('slug')->nullable()->after('name');
            }
            if (! Schema::hasColumn('plans', 'price')) {
                $table->string('price')->nullable()->after('role_id');
            }
            if (! Schema::hasColumn('plans', 'trial_days')) {
                $table->integer('trial_days')->default(0)->after('price');
            }
            if (! Schema::hasColumn('plans', 'type')) {
                $table->string('type')->default('service')->after('active');
            }
            if (! Schema::hasColumn('plans', 'product_id')) {
                $table->string('product_id')->nullable()->after('type');
            }
            if (! Schema::hasColumn('plans', 'status')) {
                $table->string('status')->default('available')->after('product_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(array_filter([
                Schema::hasColumn('plans', 'slug') ? 'slug' : null,
                Schema::hasColumn('plans', 'price') ? 'price' : null,
                Schema::hasColumn('plans', 'trial_days') ? 'trial_days' : null,
                Schema::hasColumn('plans', 'type') ? 'type' : null,
                Schema::hasColumn('plans', 'product_id') ? 'product_id' : null,
                Schema::hasColumn('plans', 'status') ? 'status' : null,
            ]));
        });
    }
};

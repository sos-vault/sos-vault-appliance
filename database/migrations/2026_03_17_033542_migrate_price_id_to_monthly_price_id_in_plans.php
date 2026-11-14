<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add the missing price columns if they don't exist yet.
        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'monthly_price_id')) {
                $table->string('monthly_price_id', 191)->nullable()->after('product_id');
            }
            if (! Schema::hasColumn('plans', 'monthly_price')) {
                $table->decimal('monthly_price', 10, 2)->nullable()->after('monthly_price_id');
            }
            if (! Schema::hasColumn('plans', 'yearly_price_id')) {
                $table->string('yearly_price_id', 191)->nullable()->after('monthly_price');
            }
            if (! Schema::hasColumn('plans', 'yearly_price')) {
                $table->decimal('yearly_price', 10, 2)->nullable()->after('yearly_price_id');
            }
            if (! Schema::hasColumn('plans', 'onetime_price_id')) {
                $table->string('onetime_price_id', 191)->nullable()->after('yearly_price');
            }
            if (! Schema::hasColumn('plans', 'onetime_price')) {
                $table->decimal('onetime_price', 10, 2)->nullable()->after('onetime_price_id');
            }
            if (! Schema::hasColumn('plans', 'active')) {
                $table->boolean('active')->default(true)->after('default');
            }
        });

        // Copy price_id → monthly_price_id where monthly_price_id is not already set.
        if (Schema::hasColumn('plans', 'price_id')) {
            DB::table('plans')
                ->whereNotNull('price_id')
                ->where('price_id', '!=', '')
                ->where(function ($q) {
                    $q->whereNull('monthly_price_id')->orWhere('monthly_price_id', '');
                })
                ->update(['monthly_price_id' => DB::raw('price_id')]);

            Schema::table('plans', function (Blueprint $table) {
                $table->dropColumn('price_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('price_id', 191)->nullable();
        });

        DB::table('plans')->update(['price_id' => DB::raw('monthly_price_id')]);

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['monthly_price_id', 'monthly_price', 'yearly_price_id', 'yearly_price', 'onetime_price_id', 'onetime_price', 'active']);
        });
    }
};

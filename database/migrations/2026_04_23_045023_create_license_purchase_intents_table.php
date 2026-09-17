<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_purchase_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('verification_id')->nullable()->constrained('license_verifications')->nullOnDelete();
            $table->unsignedSmallInteger('seats')->default(1);
            $table->json('features');
            $table->string('bundle_key');
            $table->string('cycle', 16)->default('year'); // 'month' | 'year'
            $table->string('paddle_price_id');
            $table->string('paddle_transaction_id')->nullable()->unique();
            $table->enum('status', ['pending', 'completed', 'cancelled', 'failed'])->default('pending');
            $table->foreignId('license_id')->nullable()->constrained('licenses')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_purchase_intents');
    }
};

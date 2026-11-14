<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->json('machine_tokens');
            $table->unsignedSmallInteger('seats')->default(1);
            $table->json('features');
            $table->enum('status', ['ACTIVE', 'EXPIRED', 'REVOKED'])->default('ACTIVE');
            $table->longText('signed_license')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};

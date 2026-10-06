<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_licenses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('customer_id');
            $table->json('machine_tokens');
            $table->unsignedSmallInteger('seats')->default(1);
            $table->json('features');
            $table->enum('status', ['ACTIVE', 'EXPIRED', 'REVOKED'])->default('ACTIVE');
            $table->longText('signed_license');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_licenses');
    }
};

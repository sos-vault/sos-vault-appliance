<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alert_triggers')) {
            return;
        }

        Schema::create('alert_triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('alerts')->cascadeOnDelete();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('vault_id');
            $table->unsignedBigInteger('dir_id')->nullable();
            $table->string('matched_path')->nullable();
            $table->json('detail')->nullable();
            $table->json('delivered_channels')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'matched_path']);
            $table->index(['vault_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_triggers');
    }
};

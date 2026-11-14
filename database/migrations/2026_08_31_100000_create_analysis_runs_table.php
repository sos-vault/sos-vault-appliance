<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('analysis_runs')) {
            return;
        }

        Schema::create('analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('vault_id');
            $table->unsignedBigInteger('dir_id')->nullable();
            $table->string('status', 20)->default('queued');
            $table->string('triggered_by', 20)->default('unpack');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'created_at']);
            $table->index(['vault_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_runs');
    }
};

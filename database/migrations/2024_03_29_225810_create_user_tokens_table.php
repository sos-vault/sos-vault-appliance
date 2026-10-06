<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_tokens')) {
            return;
        }

        Schema::create('user_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->integer('input_tokens_used')->default(0);
            $table->integer('output_tokens_used')->default(0);
            $table->integer('total_tokens_used')->default(0);
            $table->integer('queries_made')->default(0);
            $table->integer('reports_made')->default(0);
            $table->integer('input_tokens_used_current_session')->default(0);
            $table->integer('output_tokens_used_current_session')->default(0);
            $table->integer('total_tokens_used_current_session')->default(0);
            $table->integer('queries_per_current_session')->default(0);
            $table->integer('reports_per_current_session')->default(0);
            $table->integer('input_tokens_used_last_session')->default(0);
            $table->integer('output_tokens_used_last_session')->default(0);
            $table->integer('total_tokens_used_last_session')->default(0);
            $table->integer('queries_per_last_session')->default(0);
            $table->integer('reports_per_last_session')->default(0);
            $table->decimal('average_input_tokens_used_per_session', 15, 2)->default(0);
            $table->decimal('average_output_tokens_used_per_session', 15, 2)->default(0);
            $table->decimal('average_total_tokens_used_per_session', 15, 2)->default(0);
            $table->decimal('average_queries_per_session', 15, 2)->default(0);
            $table->decimal('average_reports_per_session', 15, 2)->default(0);
            $table->decimal('error_average_input_tokens_used_per_session', 15, 2)->default(0);
            $table->decimal('error_average_output_tokens_used_per_session', 15, 2)->default(0);
            $table->decimal('error_average_total_tokens_used_per_session', 15, 2)->default(0);
            $table->decimal('error_average_queries_per_session', 15, 2)->default(0);
            $table->decimal('error_average_reports_per_session', 15, 2)->default(0);
            $table->integer('number_of_sessions')->default(0);
            $table->integer('input_tokens_available')->default(0);
            $table->integer('output_tokens_available')->default(0);
            $table->integer('total_tokens_available')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tokens');
    }
};

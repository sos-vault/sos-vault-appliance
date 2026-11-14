<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compliance_findings')) {
            return;
        }

        Schema::create('compliance_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_run_id')->constrained('analysis_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('case_id');
            $table->unsignedBigInteger('vault_id');
            $table->string('ruleset', 20);
            $table->string('rule_id', 100);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('severity', 20);
            $table->string('status', 20);
            $table->string('category', 100)->nullable();
            $table->string('matched_path')->nullable();
            $table->json('evidence')->nullable();
            $table->text('remediation')->nullable();
            $table->string('reference_url')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'ruleset', 'status']);
            $table->index(['vault_id', 'severity', 'status']);
            $table->index('rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_findings');
    }
};

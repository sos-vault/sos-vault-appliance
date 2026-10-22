<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alerts')) {
            return;
        }

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vault_id');
            $table->unsignedBigInteger('user_id');
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('severity', 20)->default('WARNING');
            $table->boolean('enabled')->default(true);

            // discriminator: grep | levels | diff
            $table->string('type', 20);

            // type A: grep
            $table->string('grep_path')->nullable();
            $table->string('grep_regex', 500)->nullable();
            $table->string('grep_match_mode', 20)->nullable(); // found | not_found

            // type B: levels
            $table->string('levels_metric', 30)->nullable();
            $table->decimal('levels_threshold', 10, 2)->nullable();
            $table->string('levels_mount_path')->nullable();

            // type C: diff vs a fixed reference case
            $table->string('diff_path')->nullable();
            $table->unsignedBigInteger('diff_reference_case_id')->nullable();

            // delivery channels
            $table->boolean('notify_enabled')->default(false);
            $table->boolean('email_enabled')->default(false);
            $table->text('email_addresses')->nullable();
            $table->boolean('event_enabled')->default(false);
            $table->boolean('contact_point_enabled')->default(false);
            $table->string('contact_point_destination', 30)->nullable();
            $table->text('contact_point_webhook_url_encrypted')->nullable();

            $table->timestamps();

            $table->index(['vault_id', 'enabled']);
            $table->foreign('diff_reference_case_id')->references('id')->on('support_cases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};

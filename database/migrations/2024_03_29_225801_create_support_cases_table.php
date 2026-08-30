<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_cases')) {
            return;
        }

        Schema::create('support_cases', function (Blueprint $table) {
            $table->id();
            $table->boolean('secured')->default(false);
            $table->boolean('gpg')->default(false);
            $table->boolean('tar')->default(false);
            $table->boolean('obfuscated')->default(false);
            $table->string('path')->nullable();
            $table->string('sosreport')->nullable();
            $table->string('label')->nullable();
            $table->string('host')->nullable();
            $table->string('case')->nullable();
            $table->string('date')->nullable();
            $table->string('sosid')->nullable();
            $table->string('compression')->nullable();
            $table->string('customer')->nullable();
            $table->string('version')->nullable();
            $table->text('link')->nullable();
            $table->integer('serial')->default(0);
            $table->unsignedBigInteger('file_id')->default(0);
            $table->string('fstatus')->default('AVAILABLE');
            $table->unsignedBigInteger('owner');
            $table->integer('group');
            $table->string('perms');
            $table->unsignedBigInteger('subscription_id')->default(0);
            $table->string('plan_id')->default('0');
            $table->unsignedBigInteger('role_id')->default(0);
            $table->unsignedBigInteger('vault_id')->default(0);
            $table->string('status')->nullable()->default('OPEN');
            $table->text('description')->nullable();
            $table->text('root_cause')->nullable();
            $table->text('recommendation')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_cases');
    }
};

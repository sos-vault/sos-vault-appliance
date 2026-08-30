<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vaults')) {
            return;
        }

        Schema::create('vaults', function (Blueprint $table) {
            $table->id();
            $table->string('user_vault');
            $table->string('device');
            $table->string('header_file');
            $table->string('key', 2048);
            $table->string('status')->default('CLOSED');
            $table->unsignedBigInteger('owner')->unique();
            $table->integer('group');
            $table->string('perms');
            $table->string('shared_status')->default('PRIVATE');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('subscription_id')->default(0);
            $table->string('plan_id')->default('');
            $table->unsignedBigInteger('role_id')->default(0);
            $table->integer('current_size')->default(0);
            $table->integer('plan_size')->default(0);
            $table->timestamp('last_open')->nullable();
            $table->timestamp('last_close')->nullable();
            $table->timestamp('last_quota_exceeded')->nullable();
            $table->string('newkey', 2048)->nullable();
            $table->text('bookmarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vaults');
    }
};

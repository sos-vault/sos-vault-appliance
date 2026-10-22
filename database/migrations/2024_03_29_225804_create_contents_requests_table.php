<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contents_requests')) {
            return;
        }

        Schema::create('contents_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vault_id')->default(0);
            $table->unsignedBigInteger('dir_id')->default(0);
            $table->unsignedBigInteger('file_id')->default(0);
            $table->string('status')->default('VALID');
            $table->text('comments')->nullable();
            $table->text('url')->nullable();
            $table->unsignedBigInteger('owner');
            $table->integer('group');
            $table->string('perms');
            $table->unsignedBigInteger('subscription_id')->default(0);
            $table->string('plan_id')->default('0');
            $table->unsignedBigInteger('role_id')->default(0);
            $table->timestamp('expire')->useCurrent();
            $table->string('tool_name')->nullable();
            $table->unsignedBigInteger('case_id')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents_requests');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('annotations')) {
            return;
        }

        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vault_id')->default(0);
            $table->unsignedBigInteger('dir_id')->default(0);
            $table->unsignedBigInteger('file_id')->default(0);
            $table->text('title')->nullable();
            $table->string('status')->default('PRIVATE');
            $table->boolean('locked')->default(false);
            $table->longText('acetate')->nullable();
            $table->unsignedBigInteger('owner');
            $table->integer('group');
            $table->string('perms');
            $table->unsignedBigInteger('subscription_id')->default(0);
            $table->string('plan_id')->default('0');
            $table->unsignedBigInteger('role_id')->default(0);
            $table->timestamp('expire')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotations');
    }
};

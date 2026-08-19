<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sysevents')) {
            return;
        }

        Schema::create('sysevents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vault_id')->default(0);
            $table->unsignedBigInteger('dir_id')->default(0);
            $table->unsignedBigInteger('case_id')->default(0);
            $table->string('status')->default('SUCCESS');
            $table->string('type')->nullable();
            $table->string('class')->default('NORMAL');
            $table->longText('payload')->nullable();
            $table->unsignedBigInteger('owner');
            $table->integer('group');
            $table->string('ip')->nullable();
            $table->string('iso_code')->nullable();
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('timezone')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sysevents');
    }
};

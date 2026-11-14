<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_threads')) {
            return;
        }

        Schema::create('user_threads', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->integer('gid');
            $table->string('thread_id')->nullable();
            $table->boolean('uploadFiles')->default(true);
            $table->integer('wordLimit')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_threads');
    }
};

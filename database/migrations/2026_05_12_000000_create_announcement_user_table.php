<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('announcement_user')) {
            return;
        }

        Schema::create('announcement_user', function (Blueprint $table) {
            $table->foreignId('announcement_id')->constrained('announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->index('announcement_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_user');
    }
};

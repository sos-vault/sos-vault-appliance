<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // NULL = personal session; set = group/team session (future)
            $table->unsignedBigInteger('group_id')->nullable()->index();
            // The case this session is scoped to (NULL = general help)
            $table->unsignedBigInteger('case_directory_id')->nullable()->index();
            $table->unsignedBigInteger('case_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->boolean('is_group')->default(false);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_sessions');
    }
};

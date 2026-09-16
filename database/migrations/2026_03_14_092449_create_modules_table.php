<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->enum('package_type', ['module', 'patch']);
            $table->string('module_id')->unique();
            $table->string('name');
            $table->string('version');
            $table->string('description')->nullable();
            $table->string('author')->nullable();
            $table->string('provider')->nullable();
            $table->string('tool_name')->nullable();
            $table->string('tool_slug')->nullable();
            $table->string('tool_icon')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('installed_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};

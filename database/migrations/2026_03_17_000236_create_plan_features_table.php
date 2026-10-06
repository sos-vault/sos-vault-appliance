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
        Schema::create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plan_id');
            $table->foreign('plan_id')->references('id')->on('plans')->onDelete('cascade');
            $table->string('name');
            $table->enum('type', ['numeric', 'bool'])->default('bool');
            $table->boolean('enabled')->default(true);
            $table->decimal('amount', 12, 4)->nullable();
            $table->string('units')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['ready', 'pending', 'available'])->default('ready');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_features');
    }
};

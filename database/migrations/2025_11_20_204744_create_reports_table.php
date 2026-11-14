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
            Schema::create('reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('case_id')->constrained('support_cases')->onDelete('cascade');
                $table->foreignId('vault_id')->constrained('vaults')->onDelete('cascade');
                $table->integer('dir_id');
                $table->string('name')->nullable();
                $table->string('title');
                $table->text('excerpt')->nullable();
                $table->text('document');
                $table->string('image')->nullable();
                $table->string('description')->nullable();
                $table->string('keywords')->nullable();
                $table->string('type')->default('INCIDENT');
                $table->string('status')->default('DRAFT');
                $table->timestamps();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::dropIfExists('reports');
        }
    };

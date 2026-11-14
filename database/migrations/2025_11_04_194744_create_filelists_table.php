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
            Schema::create('file_lists', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('case_id')->constrained('support_cases')->onDelete('cascade');
                $table->foreignId('vault_id')->constrained('vaults')->onDelete('cascade');
                $table->integer('dir_id');
                $table->string('name');
                $table->string('title');
                $table->string('status')->default('available');
                $table->string('icon')->default("phosphor-files-duotone");
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::dropIfExists('file_lists');
        }
    };

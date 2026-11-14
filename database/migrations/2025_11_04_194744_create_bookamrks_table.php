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
            Schema::create('bookmarks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('case_id')->constrained('support_cases')->onDelete('cascade');
                $table->foreignId('vault_id')->constrained('vaults')->onDelete('cascade');
                $table->integer('dir_id');
                $table->foreignId('filelist_id')->nullable()->constrained('file_lists')->onDelete('set null');
                $table->string('name');
                $table->string('fullpath');
                $table->string('filetype');
                $table->string('icon')->default("phosphor-file-duotone");
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        /**
         * Reverse the migrations.
         */
        public function down(): void
        {
            Schema::dropIfExists('bookmarks');
        }
    };

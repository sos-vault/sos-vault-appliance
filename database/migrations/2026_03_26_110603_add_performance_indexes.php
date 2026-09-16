<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // annotations: WHERE vault_id + dir_id + group (ListDirectories::delete)
        Schema::table('annotations', function (Blueprint $table) {
            $table->index(['vault_id', 'dir_id', 'group'], 'annotations_vault_dir_group_idx');
        });

        // contents_requests: WHERE vault_id + dir_id + group (ListDirectories::delete)
        Schema::table('contents_requests', function (Blueprint $table) {
            $table->index(['vault_id', 'dir_id', 'group'], 'contents_requests_vault_dir_group_idx');
        });

        // bookmarks: WHERE user_id + vault_id + dir_id (ListDirectories::delete)
        Schema::table('bookmarks', function (Blueprint $table) {
            $table->index(['user_id', 'vault_id', 'dir_id'], 'bookmarks_user_vault_dir_idx');
        });

        // file_lists: WHERE user_id + vault_id + dir_id (ListDirectories::delete)
        Schema::table('file_lists', function (Blueprint $table) {
            $table->index(['user_id', 'vault_id', 'dir_id'], 'file_lists_user_vault_dir_idx');
        });

        // sysevents: WHERE vault_id + dir_id + group (ListDirectories::delete status update)
        Schema::table('sysevents', function (Blueprint $table) {
            $table->index(['vault_id', 'dir_id', 'group'], 'sysevents_vault_dir_group_idx');
        });

        // support_cases: WHERE group + path (VaultContent N+1 lookup per directory row)
        // support_cases: WHERE vault_id + file_id (ListDirectories::delete lookup)
        Schema::table('support_cases', function (Blueprint $table) {
            $table->index(['group', 'path'], 'support_cases_group_path_idx');
            $table->index(['vault_id', 'file_id'], 'support_cases_vault_file_idx');
        });
    }

    public function down(): void
    {
        Schema::table('annotations', function (Blueprint $table) {
            $table->dropIndex('annotations_vault_dir_group_idx');
        });

        Schema::table('contents_requests', function (Blueprint $table) {
            $table->dropIndex('contents_requests_vault_dir_group_idx');
        });

        Schema::table('bookmarks', function (Blueprint $table) {
            $table->dropIndex('bookmarks_user_vault_dir_idx');
        });

        Schema::table('file_lists', function (Blueprint $table) {
            $table->dropIndex('file_lists_user_vault_dir_idx');
        });

        Schema::table('sysevents', function (Blueprint $table) {
            $table->dropIndex('sysevents_vault_dir_group_idx');
        });

        Schema::table('support_cases', function (Blueprint $table) {
            $table->dropIndex('support_cases_group_path_idx');
            $table->dropIndex('support_cases_vault_file_idx');
        });
    }
};

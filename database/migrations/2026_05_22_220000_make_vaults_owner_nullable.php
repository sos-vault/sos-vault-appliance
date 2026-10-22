<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Appliance group-vault sprint — allow vaults.owner to be NULL so an
 * appliance group can own a vault that no individual user owns. The
 * existing UNIQUE index stays in place: MySQL/MariaDB treat multiple
 * NULLs as distinct, so the "one personal vault per user" invariant
 * for SaaS users is preserved (any non-null owner still has to be
 * unique).
 *
 * SQLite caveat: the original create_vaults_table migration declares
 * `$table->unsignedBigInteger('owner')->unique()` — an inline UNIQUE.
 * Laravel's change() on SQLite rebuilds the table and would try to
 * recreate the inline unique index using the reserved
 * 'sqlite_autoindex_*' name pattern, which SQLite rejects. We branch
 * on the driver to rebuild manually via raw SQL on SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->upSqlite();

            return;
        }

        Schema::table('vaults', function (Blueprint $table) {
            $table->unsignedBigInteger('owner')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Down is data-destructive (rows with NULL owner would violate
        // NOT NULL). Skip on SQLite; on MySQL/MariaDB Laravel's change()
        // will fail loudly if NULLs are present, which is the safer
        // signal than silently corrupting.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('vaults', function (Blueprint $table) {
            $table->unsignedBigInteger('owner')->nullable(false)->change();
        });
    }

    private function upSqlite(): void
    {
        $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type='table' AND name='vaults'");
        if (! $row) {
            return;
        }
        $createSql = $row->sql;

        // Already in target state (no NOT NULL and no inline UNIQUE on
        // owner). Some dev DBs have been edited manually to this shape;
        // skip the rebuild and just ensure an explicit unique index
        // exists for the column.
        $hasNotNull = (bool) preg_match('/"owner"\s+\w+\s+not\s+null/i', $createSql);
        $hasInlineUnique = (bool) preg_match('/"owner"\s+\w+(\s+not\s+null)?\s+unique/i', $createSql);

        if (! $hasNotNull && ! $hasInlineUnique) {
            $this->ensureExplicitUniqueIndex();

            return;
        }

        // Rebuild the table: rewrite the owner column to drop NOT NULL
        // and inline UNIQUE. The unique constraint is re-added below as
        // an explicit (named) index so future change() calls can drop
        // and recreate it without colliding with SQLite's reserved
        // auto-name prefix.
        $newCreate = preg_replace(
            '/"owner"\s+\w+(\s+not\s+null)?(\s+unique)?/i',
            '"owner" integer',
            $createSql
        );
        $tmpCreate = str_replace('CREATE TABLE "vaults"', 'CREATE TABLE "vaults_tmp_owner_nullable"', $newCreate);

        DB::transaction(function () use ($tmpCreate) {
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement($tmpCreate);
            DB::statement('INSERT INTO vaults_tmp_owner_nullable SELECT * FROM vaults');
            DB::statement('DROP TABLE vaults');
            DB::statement('ALTER TABLE vaults_tmp_owner_nullable RENAME TO vaults');
            DB::statement('PRAGMA foreign_keys = ON');
        });

        $this->ensureExplicitUniqueIndex();
    }

    private function ensureExplicitUniqueIndex(): void
    {
        $existing = DB::select(
            "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='vaults' AND name='vaults_owner_unique'"
        );
        if (! empty($existing)) {
            return;
        }

        Schema::table('vaults', function (Blueprint $table) {
            $table->unique('owner');
        });
    }
};

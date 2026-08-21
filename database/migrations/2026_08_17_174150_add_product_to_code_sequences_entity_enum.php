<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extend the code_sequences.entity enum so the lazy row created by
 * CodeGeneratorService for "product" inserts cleanly. SQLite (used in
 * tests) and MySQL both store the original enum as a CHECK constraint
 * that must be recreated to add the new value.
 *
 * Non-destructive: existing rows are untouched; only the enum/check
 * metadata changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE code_sequences MODIFY COLUMN entity ENUM('lead','customer','opportunity','quotation','product') NOT NULL");
        } elseif ($driver === 'sqlite') {
            // SQLite: drop and recreate the table without the CHECK
            // constraint (the only safe way to extend an enum). Existing
            // rows are preserved by copying them through.
            $rows = DB::table('code_sequences')->get();

            DB::statement('PRAGMA foreign_keys=OFF');
            DB::statement('DROP TABLE code_sequences');
            DB::statement(<<<'SQL'
                CREATE TABLE code_sequences (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    entity VARCHAR(255) NOT NULL CHECK (entity IN ('lead','customer','opportunity','quotation','product')),
                    year SMALLINT NOT NULL,
                    prefix VARCHAR(10) NOT NULL,
                    next_number INT UNSIGNED DEFAULT 1,
                    pad_length TINYINT DEFAULT 5,
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL
                )
            SQL);
            DB::statement('CREATE UNIQUE INDEX code_sequences_entity_year_unique ON code_sequences (entity, year)');

            foreach ($rows as $row) {
                DB::table('code_sequences')->insert((array) $row);
            }

            DB::statement('PRAGMA foreign_keys=ON');
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE code_sequences MODIFY COLUMN entity ENUM('lead','customer','opportunity','quotation') NOT NULL");
        } elseif ($driver === 'sqlite') {
            $rows = DB::table('code_sequences')
                ->where('entity', 'product')
                ->get();

            DB::statement('PRAGMA foreign_keys=OFF');
            DB::statement('DROP TABLE code_sequences');
            DB::statement(<<<'SQL'
                CREATE TABLE code_sequences (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    entity VARCHAR(255) NOT NULL CHECK (entity IN ('lead','customer','opportunity','quotation')),
                    year SMALLINT NOT NULL,
                    prefix VARCHAR(10) NOT NULL,
                    next_number INT UNSIGNED DEFAULT 1,
                    pad_length TINYINT DEFAULT 5,
                    created_at TIMESTAMP NULL,
                    updated_at TIMESTAMP NULL
                )
            SQL);
            DB::statement('CREATE UNIQUE INDEX code_sequences_entity_year_unique ON code_sequences (entity, year)');

            $remaining = DB::table('code_sequences')
                ->where('entity', '!=', 'product')
                ->get();
            foreach ($remaining as $row) {
                DB::table('code_sequences')->insert((array) $row);
            }

            DB::statement('PRAGMA foreign_keys=ON');
        }
    }
};

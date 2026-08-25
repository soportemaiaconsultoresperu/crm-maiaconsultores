<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE code_sequences MODIFY COLUMN entity ENUM('lead','customer','opportunity','quotation','product','support_ticket') NOT NULL");
        } elseif ($driver === 'sqlite') {
            $rows = DB::table('code_sequences')->get();

            DB::statement('PRAGMA foreign_keys=OFF');
            DB::statement('DROP TABLE code_sequences');
            DB::statement(<<<'SQL'
                CREATE TABLE code_sequences (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    entity VARCHAR(255) NOT NULL CHECK (entity IN ('lead','customer','opportunity','quotation','product','support_ticket')),
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
            DB::statement("ALTER TABLE code_sequences MODIFY COLUMN entity ENUM('lead','customer','opportunity','quotation','product') NOT NULL");
        } elseif ($driver === 'sqlite') {
            $rows = DB::table('code_sequences')->where('entity', '!=', 'support_ticket')->get();

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
};

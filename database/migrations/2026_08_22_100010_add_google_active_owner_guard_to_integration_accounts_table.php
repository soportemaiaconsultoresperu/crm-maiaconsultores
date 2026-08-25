<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_accounts', function (Blueprint $table): void {
            // Cross-database soft-delete-aware uniqueness guard for Phase 1:
            // application code writes owner_id here only for active Google rows.
            // The UNIQUE index then prevents two active Google identities for the
            // same CRM user, while NULL still allows non-Google and soft-deleted
            // rows. A generated/partial index would be stricter for raw SQL, but
            // this nullable guard stays portable across SQLite test DBs and MySQL.
            $table->unsignedBigInteger('google_active_owner_id')->nullable()->after('owner_id');
            $table->unique('google_active_owner_id', 'uq_integration_accounts_google_active_owner');
        });
    }

    public function down(): void
    {
        Schema::table('integration_accounts', function (Blueprint $table): void {
            $table->dropUnique('uq_integration_accounts_google_active_owner');
            $table->dropColumn('google_active_owner_id');
        });
    }
};

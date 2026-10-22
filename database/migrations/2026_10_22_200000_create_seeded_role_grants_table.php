<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger of the default permissions the seeders already handed to a role
 * (App\Support\Permissions\SeededGrants). Production re-runs the seeders on
 * every deploy; a ledger row means "granted once, the admin owns it now", so a
 * permission the admin later removes from the role is never re-granted.
 * Purely additive: it creates a table and touches no existing data.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('seeded_role_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('permission_name', 191);
            $table->timestamp('granted_at');
            $table->unique(['role_id', 'permission_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seeded_role_grants');
    }
};

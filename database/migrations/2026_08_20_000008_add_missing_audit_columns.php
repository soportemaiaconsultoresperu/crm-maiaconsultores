<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Several models use the HasAuditColumns trait, which auto-fills
 * `created_by` and `updated_by` on every INSERT/UPDATE. Some of their
 * tables are missing one or both columns, causing SQLSTATE[42S22]
 * "Unknown column 'created_by' in 'field list'" (or `updated_by`)
 * on every save. This migration adds the missing columns.
 *
 *   - campaign_participants   (both)
 *   - campaign_action_items   (both)
 *   - documents               (both)
 *   - integration_accounts    (both)
 *   - automation_rules        (updated_by only)
 *   - email_templates         (updated_by only)
 *
 * Columns are nullable and FK to `users`. Existing rows are not back-filled
 * (nullable), so the migration is safe to run on populated tables; the
 * audit trail will simply show NULL for the rows that existed before.
 */
return new class extends Migration
{
    public function up(): void
    {
        $both = ['campaign_participants', 'campaign_action_items', 'documents', 'integration_accounts'];
        foreach ($both as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->foreignId('created_by')->nullable()->constrained('users');
                $t->foreignId('updated_by')->nullable()->constrained('users');
            });
        }

        $updatedOnly = ['automation_rules', 'email_templates'];
        foreach ($updatedOnly as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->foreignId('updated_by')->nullable()->constrained('users');
            });
        }
    }

    public function down(): void
    {
        $both = ['campaign_participants', 'campaign_action_items', 'documents', 'integration_accounts'];
        foreach ($both as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropForeign(['created_by', 'updated_by']);
                $t->dropColumn(['created_by', 'updated_by']);
            });
        }

        $updatedOnly = ['automation_rules', 'email_templates'];
        foreach ($updatedOnly as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropForeign(['updated_by']);
                $t->dropColumn(['updated_by']);
            });
        }
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `App\Models\CampaignItemReschedule` uses the `HasAuditColumns` trait, which
 * auto-fills `created_by` on INSERT and `updated_by` on UPDATE. Like its sibling
 * campaign tables (`campaign_templates`, `campaign_runs`, `campaign_steps`,
 * `campaign_participants`, `campaign_action_items`) it therefore writes audit
 * columns that its own table does not have: `campaign_item_reschedules` was
 * omitted from `2026_08_20_000008_add_missing_audit_columns`.
 *
 * Result: EVERY insert into the reschedule history fails with
 * `SQLSTATE[HY000]: table campaign_item_reschedules has no column named
 * created_by`, so both `CampaignRescheduleService::rescheduleIndividual()` and
 * `::rescheduleAll()` returned a 500 for anyone who got past the gate. That was
 * invisible because the campaign endpoints denied every non-admin actor; the
 * admin reached them through the role bypass and hit the crash.
 *
 * This is a SEPARATE, ADDITIVE migration on purpose. The sibling migration is
 * already applied everywhere, so editing it would leave every existing database
 * still broken. Forward effect: two NULLABLE columns, each an FK to `users`, on
 * `campaign_item_reschedules`. Existing rows keep their values and get NULL in
 * the new columns (no back-fill, no NOT NULL, no default), so the change is
 * safe on a populated table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_item_reschedules', function (Blueprint $t): void {
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->foreignId('updated_by')->nullable()->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_item_reschedules', function (Blueprint $t): void {
            // Drop each foreign key with a SINGLE-column array. Laravel matches
            // the constraint by comparing `columns` exactly, so a combined
            // `dropForeign(['created_by', 'updated_by'])` matches no constraint at
            // all and leaves SQLite refusing to drop the columns ("unknown column
            // created_by in foreign key definition").
            $t->dropForeign(['created_by']);
            $t->dropForeign(['updated_by']);
            $t->dropColumn(['created_by', 'updated_by']);
        });
    }
};

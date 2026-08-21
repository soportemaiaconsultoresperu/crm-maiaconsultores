<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CampaignParticipant model declares the SoftDeletes trait, so every
 * query Laravel issues against it includes `where deleted_at is null`.
 * The original `create_campaign_participants_table` migration did NOT
 * create that column, so `updateOrCreate` (and any other query) throws
 * SQLSTATE[42S22] Unknown column 'campaign_participants.deleted_at'.
 *
 * This migration closes the gap. It does NOT change semantics: participant
 * exclusion is still represented by `status` + `excluded_at` + the audit
 * fields; soft delete is only the safety net for hard `delete()` calls.
 *
 * NOTE about the existing `unique(run_id, subject_type, subject_id)` index:
 * it does not include `deleted_at`, so re-creating a previously soft-deleted
 * participant with the same triple will violate the constraint. If that
 * flow becomes a real requirement, swap the plain unique for a partial /
 * composite unique that excludes rows where `deleted_at IS NOT NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_participants', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_participants', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
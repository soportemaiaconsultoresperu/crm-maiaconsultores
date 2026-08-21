<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B17 — notification_preferences.
 *
 * One row per (user, subject_type, channel) triplet describing whether a
 * given recipient wants to receive notifications about a given subject via a
 * given channel. The `subject_type` follows the same morph-alias convention
 * used elsewhere in the codebase (e.g. V1 `activity.subject_type` /
 * `automation_cycle_breaks.subject_type`).
 *
 * Per docs/v2/01-roadmap.md §2.7 and §10 (D-21a..D-21g). The `scope` column
 * distinguishes configurable commercial notifications (`optional`) from the
 * mandatory administrative / security triggers (D-21a..D-21d) that the user
 * cannot opt out of — D-21f (new-device detection) and D-21g (SLA) are
 * explicitly out of scope for V2.
 *
 * Status values are validated at the application layer via the SCOPE_*
 * constants on {@see \App\Models\Notification\NotificationPreference}
 * (no new MySQL ENUMs per C-03).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('user_id');
            $table->string('subject_type', 80);
            $table->string('channel', 16);
            $table->boolean('enabled')->default(true);
            $table->string('scope', 16)->default('optional');

            $table->timestamps();

            $table->unique(['user_id', 'subject_type', 'channel'], 'uq_notification_preferences_triplet');
            $table->index('user_id', 'idx_notification_preferences_user');
            $table->index('subject_type', 'idx_notification_preferences_subject');
        });

        if (Schema::hasTable('users')) {
            Schema::table('notification_preferences', function (Blueprint $table): void {
                $table->foreign('user_id', 'fk_notification_preferences_user')
                    ->references('id')->on('users')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_preferences')) {
            Schema::table('notification_preferences', function (Blueprint $table): void {
                $table->dropForeign('fk_notification_preferences_user');
            });
        }

        Schema::dropIfExists('notification_preferences');
    }
};

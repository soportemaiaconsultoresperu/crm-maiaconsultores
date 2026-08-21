<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ConsentRecord;
use App\Models\SuppressionEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * B21 — Centralized consent + suppression service.
 *
 * Single source of truth for "is this subject eligible to receive a campaign
 * on this channel?". Combines:
 *   1. Suppression list (hard block — opt_out, bounce, complaint, manual).
 *   2. Active consent record (positive opt-in required for the channel + purpose).
 *
 * B22 calls `isEligible($subject, $channel, $purpose)` before every dispatch.
 *
 * # JP rules enforced
 *   - Default opt-in for all channels (no contact without explicit consent).
 *   - Suppression entries are global OR per-channel; global wins.
 *   - Revocation is terminal: once a subject revokes, they don't get re-added
 *     to the active list by a stale import. New grant creates a new row.
 *   - Evidence is required (INDECOPI compliance): service writes a hash
 *     placeholder if evidence is empty (this is rejected by validation; the
 *     controller / form-request must validate it).
 */
class ConsentService
{
    /**
     * Check if a subject is eligible to receive a campaign on a channel
     * for a given purpose. Hard-block on suppression; positive opt-in required.
     *
     * @param  Model  $subject  Contact | Customer | Lead
     * @param  string  $channel  'email' | 'whatsapp'
     * @param  string  $purpose  'marketing_newsletter' | 'promotional' | 'transactional' | 'support'
     */
    public function isEligible(\Illuminate\Database\Eloquent\Model $subject, string $channel, string $purpose): bool
    {
        $subjectType = $subject->getMorphClass();

        // 1) Suppression list: if there's an active entry for (subject, channel)
        //    OR (subject, NULL=global), the subject is blocked.
        $suppression = SuppressionEntry::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subject->getKey())
            ->active()
            ->forChannel($channel)
            ->exists();

        if ($suppression) {
            return false;
        }

        // 2) Active consent for (subject, channel, purpose) — must exist.
        $hasConsent = ConsentRecord::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subject->getKey())
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->active()
            ->exists();

        return $hasConsent;
    }

    /**
     * Grant consent. Idempotent: if a matching active consent exists, return it
     * without creating a duplicate. If a revoked consent exists, create a new
     * active row (the old revocation stands as audit history).
     *
     * @param  array<string, mixed>  $attributes  subject_type, subject_id, channel, purpose, source, evidence, ...
     */
    public function grant(array $attributes, ?User $actor = null): ConsentRecord
    {
        $attributes = array_merge([
            'status' => ConsentRecord::STATUS_ACTIVE,
            'granted_at' => now(),
            'created_by' => $actor?->id,
        ], $attributes);

        // Idempotent: if there's an existing active record, return it.
        $existing = ConsentRecord::query()
            ->where('subject_type', $attributes['subject_type'])
            ->where('subject_id', $attributes['subject_id'])
            ->where('channel', $attributes['channel'])
            ->where('purpose', $attributes['purpose'])
            ->active()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($attributes): ConsentRecord {
            return ConsentRecord::query()->create($attributes);
        });
    }

    /**
     * Revoke consent. Marks the active record as 'revoked' and writes the
     * audit fields. Does NOT delete the row (audit history preserved).
     */
    public function revoke(ConsentRecord $consent, string $reason, ?User $actor = null): ConsentRecord
    {
        return DB::transaction(function () use ($consent, $reason, $actor): ConsentRecord {
            $consent->forceFill([
                'status' => ConsentRecord::STATUS_REVOKED,
                'revoked_at' => now(),
                'revoked_reason' => $reason,
            ])->save();

            return $consent->refresh();
        });
    }

    /**
     * Record a suppression entry. Idempotent: if an active entry exists for
     * (subject, channel), return it without creating a duplicate.
     */
    public function recordSuppression(\Illuminate\Database\Eloquent\Model $subject, string $reason, ?string $channel = null, ?string $source = null, ?User $actor = null): SuppressionEntry
    {
        $existing = SuppressionEntry::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where(function ($q) use ($channel) {
                $q->whereNull('channel');
                if ($channel !== null) {
                    $q->orWhere('channel', $channel);
                }
            })
            ->active()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($subject, $reason, $channel, $source, $actor): SuppressionEntry {
            return SuppressionEntry::query()->create([
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'channel' => $channel,
                'reason' => $reason,
                'source' => $source,
                'created_by' => $actor?->id,
            ]);
        });
    }

    /**
     * Remove a suppression entry. Idempotent: no-op if no entry exists.
     */
    public function removeSuppression(\Illuminate\Database\Eloquent\Model $subject, ?string $channel = null): int
    {
        return SuppressionEntry::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->where(function ($q) use ($channel) {
                $q->whereNull('channel');
                if ($channel !== null) {
                    $q->orWhere('channel', $channel);
                }
            })
            ->delete();
    }
}

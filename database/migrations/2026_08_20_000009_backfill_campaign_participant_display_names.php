<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Lead;

/**
 * The previous fix added `CampaignRunService::displayNameFor()` so NEW
 * participants get a proper human-readable name (e.g. "Maria Gonzales",
 * "HURACORP") instead of the old "lead #N" fallback. But existing rows
 * keep the old broken snapshot — so the run matrix still shows
 * "Lead #4" / "Lead #7".
 *
 * This migration walks every `campaign_participants` row, resolves the
 * underlying subject (lead/customer/contact, including soft-deleted ones
 * so we don't lose historical data), computes the proper name with the
 * same rules, and updates the row only if it actually changes.
 *
 *   - lead:        first_name+last_name → company_name → "lead #N"
 *   - customer:    legal_name → trade_name → "customer #N"
 *   - contact:     first_name+last_name → "contact #N"
 *
 * `down()` is intentionally a no-op: we don't restore the broken names.
 */
return new class extends Migration
{
    public function up(): void
    {
        $resolve = static function (string $type, int $id): ?Model {
            return match ($type) {
                'lead'     => Lead::withTrashed()->find($id),
                'customer' => Customer::withTrashed()->find($id),
                'contact'  => Contact::withTrashed()->find($id),
                default    => null,
            };
        };

        $compute = static function (?Model $subject, string $type): ?string {
            if ($subject === null) {
                return null; // subject missing/hard-deleted: leave row untouched
            }

            if ($type === 'contact') {
                $full = trim(($subject->first_name ?? '') . ' ' . ($subject->last_name ?? ''));
                return $full !== '' ? $full : sprintf('%s #%d', $type, $subject->getKey());
            }

            if ($type === 'customer') {
                return $subject->legal_name
                    ?: ($subject->trade_name ?: sprintf('%s #%d', $type, $subject->getKey()));
            }

            // lead
            $full = trim(($subject->first_name ?? '') . ' ' . ($subject->last_name ?? ''));
            if ($full !== '') {
                return $full;
            }
            if (! empty($subject->company_name)) {
                return $subject->company_name;
            }
            return sprintf('%s #%d', $type, $subject->getKey());
        };

        $updated = 0;
        $skipped = 0;
        $unchanged = 0;

        DB::table('campaign_participants')->orderBy('id')->chunkById(100, function ($rows) use ($resolve, $compute, &$updated, &$skipped, &$unchanged) {
            foreach ($rows as $row) {
                $subject = $resolve($row->subject_type, (int) $row->subject_id);
                $newName = $compute($subject, $row->subject_type);

                if ($newName === null) {
                    $skipped++;
                    continue;
                }
                if ($newName === $row->display_name) {
                    $unchanged++;
                    continue;
                }

                DB::table('campaign_participants')
                    ->where('id', $row->id)
                    ->update(['display_name' => $newName]);
                $updated++;
            }
        });

        // Surface a quick summary in the migration log so it's auditable.
        DB::statement(
            "/* campaign_participants display_name backfill: updated={$updated}, unchanged={$unchanged}, skipped={$skipped} */"
        );
    }

    public function down(): void
    {
        // Intentionally empty. We do not restore the broken "lead #N" names.
    }
};
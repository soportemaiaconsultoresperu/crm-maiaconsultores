<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move the talk certificate price from the reusable activity (the template) to
 * the concrete delivery, and give it the name the rest of the money flow already
 * uses for that charge: `certificate_charge_amount`.
 *
 * WHY: `course_activities` is a template — one row describes a course or a talk
 * and every delivery (`course_editions`) of it inherits the same settings. A
 * talk's certificate price is NOT a template property: the same talk is sold
 * again at a different date and a different price, so the amount to charge
 * belongs to the delivery that is actually sold. The enrollment flow reads it
 * from there (`course_editions.certificate_charge_amount` ->
 * `course_enrollments.certificate_charge_amount`), which is the one direction the
 * commercial document already sums.
 *
 * DATA MEASURED BEFORE TOUCHING ANYTHING (local development database `crm_maia`,
 * 127.0.0.1, queried independently rather than trusting a second-hand report):
 *   - `SELECT id, type, talk_includes_certificate, talk_certificate_price FROM
 *     course_activities` -> ONE row: id 1, type `course`,
 *     `talk_includes_certificate` = 0, `talk_certificate_price` = '100.00'.
 *     The row is a COURSE holding a leftover price from when the form showed
 *     that field for courses too; it is an inconsistency, not a talk price.
 *   - `SELECT id, course_activity_id, price_amount FROM course_editions` -> ONE
 *     row: id 1, activity 1, price '1.00'.
 *   - `SELECT id, activity_price_amount, certificate_charge_amount, discount_amount,
 *     subtotal_amount FROM course_enrollments` -> ONE row: activity price '1.00',
 *     certificate charge '0.00', discount '0.00', subtotal '1.00'.
 * So on THIS database there is no legitimate talk price to preserve. The copy
 * step below is written for ANY database regardless — a production database may
 * hold talks that really do charge a certificate, and the copy is exactly what
 * keeps them from silently losing their price.
 *
 * Forward effect:
 *   1. adds `certificate_charge_amount` decimal(14,2) NOT NULL default 0 to
 *      `course_editions` (same shape as `course_enrollments.certificate_charge_amount`);
 *   2. copies each TALK activity's `talk_certificate_price` into every edition of
 *      that activity, so a talk that already charged a certificate keeps charging
 *      it — now per delivery, which is what a delivery-level price means;
 *      COURSE activities are deliberately NOT copied: a course never charges a
 *      talk certificate, and copying the leftover would plant a charge the domain
 *      invariant forbids;
 *   3. drops `course_activities.talk_certificate_price`, the column that was
 *      written and never read.
 *
 * Rollback effect (`down()`): re-adds `course_activities.talk_certificate_price`
 * exactly as it was (decimal(14,2) NOT NULL default 0, after
 * `talk_includes_certificate`), copies the value back from each TALK activity's
 * editions (the first edition by id, since the forward copy gave them all the same
 * value), and drops `course_editions.certificate_charge_amount`. The re-added
 * activity column therefore carries the talk price again, but two categories are
 * NOT recoverable and are stated plainly rather than glossed over:
 *   - a TALK with no editions had nowhere for its price to be copied to on the
 *     way forward, so its column comes back at the default '0.00';
 *   - a COURSE's leftover `talk_certificate_price` was meaningless (a course never
 *     charges a certificate); it is not copied forward and comes back as '0.00'.
 * That is the honest recoverable content: the schema restores exactly, the talk
 * prices that had a delivery restore exactly, and the rest was either impossible
 * (no edition to hold it) or meaningless (a course charge).
 *
 * Deliberately NOT an edit to `2026_08_26_000001_create_course_domain_foundation_tables`:
 * that migration has already run on this database and on every other one, so
 * editing it would leave each existing database holding a column the schema says
 * should not exist. A new migration is the only honest way to move a column on a
 * database that is already migrated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_editions', function (Blueprint $table): void {
            $table->decimal('certificate_charge_amount', 14, 2)->default(0)->after('price_amount');
        });

        $this->copyTalkPricesToEditions();

        Schema::table('course_activities', function (Blueprint $table): void {
            $table->dropColumn('talk_certificate_price');
        });
    }

    public function down(): void
    {
        Schema::table('course_activities', function (Blueprint $table): void {
            $table->decimal('talk_certificate_price', 14, 2)->default(0)->after('talk_includes_certificate');
        });

        $this->copyEditionChargesBackToTalkActivities();

        Schema::table('course_editions', function (Blueprint $table): void {
            $table->dropColumn('certificate_charge_amount');
        });
    }

    /**
     * Every edition of a talk inherits that talk's price, so the move preserves
     * the charge the template used to declare. Courses are skipped: they cannot
     * charge a talk certificate, so copying their leftover would plant a charge
     * the domain invariant forbids.
     */
    private function copyTalkPricesToEditions(): void
    {
        DB::table('course_activities')
            ->where('type', 'talk')
            ->orderBy('id')
            ->get(['id', 'talk_certificate_price'])
            ->each(function (object $activity): void {
                DB::table('course_editions')
                    ->where('course_activity_id', $activity->id)
                    ->update([
                        'certificate_charge_amount' => (string) ($activity->talk_certificate_price ?? '0.00'),
                    ]);
            });
    }

    /**
     * The inverse of the forward copy: each talk takes the charge of its editions
     * back onto its own column. The first edition by id wins because the forward
     * copy wrote the same value to all of them; editions that diverged afterwards
     * have no single correct answer, and a deterministic choice beats a silent one.
     */
    private function copyEditionChargesBackToTalkActivities(): void
    {
        DB::table('course_activities')
            ->where('type', 'talk')
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $activity): void {
                $charge = DB::table('course_editions')
                    ->where('course_activity_id', $activity->id)
                    ->orderBy('id')
                    ->value('certificate_charge_amount');

                DB::table('course_activities')
                    ->where('id', $activity->id)
                    ->update([
                        'talk_certificate_price' => (string) ($charge ?? '0.00'),
                    ]);
            });
    }
};

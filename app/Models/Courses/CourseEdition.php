<?php
namespace App\Models\Courses;
use App\Enums\Courses\CourseEditionState;use App\Enums\Courses\CourseModality;use App\Exceptions\Courses\InvalidCourseEditionData;use App\Models\User;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Database\Eloquent\Relations\HasMany;
class CourseEdition extends CourseModel{/**
 * The single "a delivery charges no certificate" value. Named once because it is
 * written to `course_editions.certificate_charge_amount` AND to
 * `course_enrollments.certificate_charge_amount`, and two literals that drifted
 * apart would make a course look like it still holds a charge.
 */
private const NO_CERTIFICATE_CHARGE='0.00';
protected $fillable=['course_activity_id','code','state','modality','starts_on','ends_on','address','access_url','price_amount','certificate_charge_amount','currency','syllabus_override_json','responsible_user_id','delivery_due_days','validations_completed_at'];protected function casts():array{return ['state'=>CourseEditionState::class,'modality'=>CourseModality::class,'starts_on'=>'date','ends_on'=>'date','price_amount'=>'decimal:2','certificate_charge_amount'=>'decimal:2','syllabus_override_json'=>'array','validations_completed_at'=>'datetime'];}public function activity():BelongsTo{return $this->belongsTo(CourseActivity::class,'course_activity_id');}public function responsible():BelongsTo{return $this->belongsTo(User::class,'responsible_user_id');}public function teachers():HasMany{return $this->hasMany(CourseEditionTeacher::class);}public function sessions():HasMany{return $this->hasMany(CourseSession::class);}public function enrollments():HasMany{return $this->hasMany(CourseEnrollment::class);}

/**
 * The charge THIS delivery bills for the certificate, resolved to money: the
 * stored delivery charge when the activity is a talk that includes a certificate,
 * and '0.00' otherwise. This is the ONE place the delivery's certificate money is
 * decided, so `CourseEnrollmentService::enroll()` reads the charge here instead of
 * re-deriving which activities may charge one.
 */
public function certificateCharge():string{return $this->activity?->issuesTalkCertificate() ? (string) $this->certificate_charge_amount : self::NO_CERTIFICATE_CHARGE;}

/**
 * The stored value obeys the same invariant the activity does: a COURSE (and a
 * talk without a certificate) never carries a certificate charge. Enforced on
 * WRITE, in the model both the edition service and the factory pass through, so
 * the column cannot hold a charge the predicate forbids even when a caller sets
 * it directly — no caller re-checks the rule.
 */
protected static function booted():void{static::saving(function(CourseEdition $edition):void{if(! $edition->isDirty('certificate_charge_amount')){return;}if(! $edition->activity?->issuesTalkCertificate()){$edition->certificate_charge_amount=self::NO_CERTIFICATE_CHARGE;}});}

/**
 * The per-enrollment money of THIS delivery, in the shape the screens render: what
 * attending costs, what the certificate costs, and the per-enrollment total.
 *
 * The certificate amount comes from `certificateCharge()`, the ONE place the
 * delivery's certificate money is resolved, so a course — or a talk without a
 * certificate — answers `'0.00'` here for exactly the same reason the enrollment is
 * charged nothing for it. The arithmetic is `sumMoney()`, which
 * `CourseEnrollmentService` also uses to derive the enrollment's persisted
 * `subtotal_amount`, so the total this method answers and the number the enrollment
 * stores cannot drift.
 *
 * The discount is NOT part of this: it belongs to a single enrollment, not to the
 * delivery, so the screen renders the delivery's total and the service subtracts the
 * enrollment's own discount from it.
 *
 * @return array{activity_price_amount: string, certificate_charge_amount: string, total_amount: string}
 */
public function enrollmentMoney():array{
$activityPrice=(string) $this->price_amount;
$certificateCharge=$this->certificateCharge();

return ['activity_price_amount'=>$activityPrice,'certificate_charge_amount'=>$certificateCharge,'total_amount'=>self::sumMoney($activityPrice,$certificateCharge)];
}

/**
 * The single definition of the module's money arithmetic. Money is summed in
 * INTEGER CENTS, never through a float, and formatted back to the exact `d.dd`
 * string the `decimal(14,2)` columns hold.
 *
 * `CourseEdition::enrollmentMoney()` (what the screens render) and
 * `CourseEnrollmentService::subtotalAmount()` (what the enrollment persists) both
 * call these helpers, so a displayed amount and a stored amount are the same number
 * by construction rather than two implementations that happen to agree.
 */
public static function sumMoney(string ...$amounts):string{
$cents=0;

foreach($amounts as $amount){
$cents+=self::centsOf($amount);
}

return self::moneyFromCents($cents);
}

/**
 * `$amount` minus `$discount`, refusing a NEGATIVE result instead of persisting
 * money nobody agreed to — the same non-negative rule
 * `CourseCommercialDocumentService::calculateCharges()` applies to the amount it
 * taxes.
 */
public static function subtractMoney(string $amount,string $discount):string{
$cents=self::centsOf($amount)-self::centsOf($discount);

if($cents<0){
throw new InvalidCourseEditionData('El subtotal de la matrícula no puede ser negativo.');
}

return self::moneyFromCents($cents);
}

/**
 * A non-negative amount with up to two decimals, read as integer cents without going
 * through a float. An amount that is not money is REFUSED rather than silently read
 * as zero.
 */
private static function centsOf(string $amount):int{
if(! preg_match('/^\d+(?:\.\d{1,2})?$/',$amount)){
throw new InvalidCourseEditionData("Monto de matrícula inválido: {$amount}.");
}

[$whole,$fraction]=array_pad(explode('.',$amount,2),2,'');

return ((int) $whole*100)+(int) str_pad($fraction,2,'0');
}

/** Integer cents back to the exact `d.dd` string a `decimal(14,2)` column holds. */
private static function moneyFromCents(int $cents):string{
return sprintf('%d.%02d',intdiv($cents,100),$cents % 100);
}
}

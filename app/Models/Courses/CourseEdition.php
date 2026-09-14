<?php
namespace App\Models\Courses;
use App\Enums\Courses\CourseEditionState;use App\Enums\Courses\CourseModality;use App\Models\User;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Database\Eloquent\Relations\HasMany;
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
protected static function booted():void{static::saving(function(CourseEdition $edition):void{if(! $edition->isDirty('certificate_charge_amount')){return;}if(! $edition->activity?->issuesTalkCertificate()){$edition->certificate_charge_amount=self::NO_CERTIFICATE_CHARGE;}});}}

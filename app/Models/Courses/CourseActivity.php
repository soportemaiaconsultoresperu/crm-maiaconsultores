<?php
namespace App\Models\Courses;
use App\Enums\Courses\CourseActivityType;
use Illuminate\Database\Eloquent\Relations\HasMany;
class CourseActivity extends CourseModel{protected $fillable=['type','code','name','slug','official_academic_hours','base_syllabus_json','talk_includes_certificate','is_active'];protected function casts():array{return ['type'=>CourseActivityType::class,'base_syllabus_json'=>'array','official_academic_hours'=>'decimal:2','talk_includes_certificate'=>'boolean','is_active'=>'boolean'];}public function editions():HasMany{return $this->hasMany(CourseEdition::class);}

/**
 * The ONE "does this activity charge a talk certificate" decision in the domain.
 *
 * A certificate is charged only by a TALK that includes one: a course has no talk
 * certificate at all, and a talk without a certificate has nothing to charge. The
 * rule lives here so no caller re-checks `type === Talk && talk_includes_certificate`
 * (and then drifts from it); `CourseEdition::certificateCharge()` and the edition's
 * write guard read this single predicate, so the enrollment money and the stored
 * delivery column cannot disagree about whether a certificate applies.
 */
public function issuesTalkCertificate():bool{return $this->type===CourseActivityType::Talk && (bool) $this->talk_includes_certificate;}}

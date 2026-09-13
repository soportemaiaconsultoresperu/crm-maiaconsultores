<?php
namespace App\Models\Courses;
use App\Models\User;use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CourseGrade extends CourseModel{protected $fillable=['course_session_id','course_enrollment_id','description','grade','entered_by','entered_at'];protected function casts():array{return ['grade'=>'decimal:2','entered_at'=>'datetime'];}public function session():BelongsTo{return $this->belongsTo(CourseSession::class,'course_session_id');}public function enrollment():BelongsTo{return $this->belongsTo(CourseEnrollment::class,'course_enrollment_id');}public function enteredBy():BelongsTo{return $this->belongsTo(User::class,'entered_by');}}

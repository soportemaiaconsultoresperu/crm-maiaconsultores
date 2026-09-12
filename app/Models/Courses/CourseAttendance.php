<?php
namespace App\Models\Courses;
use Illuminate\Database\Eloquent\Factories\HasFactory;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;use Spatie\Activitylog\Traits\LogsActivity;
class CourseAttendance extends Model{use HasFactory,LogsActivity;public function getActivitylogOptions():LogOptions{return CourseModel::courseActivitylogOptions();}protected $fillable=['course_session_id','course_enrollment_id','status','marked_by','marked_at'];protected function casts():array{return ['marked_at'=>'datetime'];}public function session():BelongsTo{return $this->belongsTo(CourseSession::class,'course_session_id');}public function enrollment():BelongsTo{return $this->belongsTo(CourseEnrollment::class,'course_enrollment_id');}}

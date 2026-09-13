<?php
namespace App\Models\Courses;
use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Database\Eloquent\Relations\HasMany;
class CourseSession extends CourseModel{protected $fillable=['course_edition_id','session_date','starts_at','ends_at','teacher_name','topic','sort_order'];protected function casts():array{return ['session_date'=>'date'];}public function edition():BelongsTo{return $this->belongsTo(CourseEdition::class,'course_edition_id');}public function attendances():HasMany{return $this->hasMany(CourseAttendance::class);}public function grades():HasMany{return $this->hasMany(CourseGrade::class);}}

<?php
namespace App\Models\Courses;
use App\Models\User;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Database\Eloquent\Relations\HasMany;
class CourseEditionTeacher extends CourseModel{protected $fillable=['course_edition_id','user_id','display_name','email','sort_order'];public function edition():BelongsTo{return $this->belongsTo(CourseEdition::class,'course_edition_id');}public function user():BelongsTo{return $this->belongsTo(User::class);}public function sessions():HasMany{return $this->hasMany(CourseSession::class,'teacher_id');}}

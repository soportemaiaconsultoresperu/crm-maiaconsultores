<?php
namespace App\Models\Courses;
use App\Models\User;use Illuminate\Database\Eloquent\Factories\HasFactory;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CourseEditionTeacher extends Model{use HasFactory;public $timestamps=false;protected $fillable=['course_edition_id','user_id','display_name','email','sort_order'];public function edition():BelongsTo{return $this->belongsTo(CourseEdition::class,'course_edition_id');}public function user():BelongsTo{return $this->belongsTo(User::class);}}

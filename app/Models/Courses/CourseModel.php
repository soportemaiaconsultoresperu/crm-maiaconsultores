<?php
namespace App\Models\Courses;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
abstract class CourseModel extends Model{use HasAuditColumns,HasFactory,LogsActivity,SoftDeletes;public function getActivitylogOptions():LogOptions{return LogOptions::defaults()->logOnlyDirty()->logAll()->setDescriptionForEvent(fn(string $event)=>'course-'.$event);}}

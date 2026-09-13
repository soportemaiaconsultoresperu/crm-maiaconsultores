<?php
namespace App\Models\Courses;
use App\Enums\Courses\CourseActivityType;
use Illuminate\Database\Eloquent\Relations\HasMany;
class CourseActivity extends CourseModel{protected $fillable=['type','code','name','slug','official_academic_hours','base_syllabus_json','reference_price','talk_includes_certificate','talk_certificate_price','is_active'];protected function casts():array{return ['type'=>CourseActivityType::class,'base_syllabus_json'=>'array','official_academic_hours'=>'decimal:2','reference_price'=>'decimal:2','talk_includes_certificate'=>'boolean','talk_certificate_price'=>'decimal:2','is_active'=>'boolean'];}public function editions():HasMany{return $this->hasMany(CourseEdition::class);}}

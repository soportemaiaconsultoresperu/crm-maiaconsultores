<?php
namespace Database\Factories\Courses;
use App\Enums\Courses\CourseActivityType;use App\Models\Courses\CourseActivity;use Illuminate\Database\Eloquent\Factories\Factory;
class CourseActivityFactory extends Factory{protected $model=CourseActivity::class;public function definition():array{return ['type'=>CourseActivityType::Course,'code'=>fake()->unique()->bothify('CUR-###'),'name'=>fake()->sentence(3),'slug'=>fake()->unique()->slug(),'official_academic_hours'=>'8.00','base_syllabus_json'=>['Temario base'],'reference_price'=>'100.00','talk_includes_certificate'=>false,'talk_certificate_price'=>'0.00','is_active'=>true];}}

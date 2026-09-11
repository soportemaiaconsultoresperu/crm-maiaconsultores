<?php
namespace Database\Factories\Courses;
use App\Models\Courses\CourseEdition;use App\Models\Courses\CourseSession;use Illuminate\Database\Eloquent\Factories\Factory;
class CourseSessionFactory extends Factory{protected $model=CourseSession::class;public function definition():array{return ['course_edition_id'=>CourseEdition::factory(),'session_date'=>now()->toDateString(),'starts_at'=>'09:00','ends_at'=>'11:00','teacher_name'=>fake()->name(),'topic'=>fake()->sentence(3),'sort_order'=>1];}}

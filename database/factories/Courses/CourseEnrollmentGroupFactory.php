<?php
namespace Database\Factories\Courses;
use App\Models\Courses\CourseEdition;use App\Models\Courses\CourseEnrollmentGroup;use Illuminate\Database\Eloquent\Factories\Factory;
class CourseEnrollmentGroupFactory extends Factory{protected $model=CourseEnrollmentGroup::class;public function definition():array{return ['course_edition_id'=>CourseEdition::factory(),'payer_name'=>fake()->company(),'payer_document_type'=>'ruc','payer_document_number'=>fake()->numerify('20#########'),'notes'=>null];}}

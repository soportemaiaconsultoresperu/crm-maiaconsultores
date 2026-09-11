<?php
namespace Database\Factories\Courses;
use App\Models\Courses\CourseParticipant;use Illuminate\Database\Eloquent\Factories\Factory;
class CourseParticipantFactory extends Factory{protected $model=CourseParticipant::class;public function definition():array{$dni=fake()->unique()->numerify('########');$email=fake()->unique()->safeEmail();return ['first_name'=>fake()->firstName(),'last_name'=>fake()->lastName(),'document_type'=>'dni','document_number'=>$dni,'document_number_norm'=>$dni,'email'=>$email,'email_norm'=>strtolower($email),'mobile'=>'+51999999999','mobile_norm'=>'51999999999'];}}

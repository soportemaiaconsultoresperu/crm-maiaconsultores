<?php
namespace App\Enums\Courses;
enum CourseActivityType:string{case Course='course';case Talk='talk';public function label():string{return match($this){self::Course=>'Curso',self::Talk=>'Charla'};}}

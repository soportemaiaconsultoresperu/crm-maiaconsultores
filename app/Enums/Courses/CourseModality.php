<?php
namespace App\Enums\Courses;
enum CourseModality:string{case Presential='presential';case Virtual='virtual';case Hybrid='hybrid';public function label():string{return match($this){self::Presential=>'Presencial',self::Virtual=>'Virtual',self::Hybrid=>'Híbrida'};}}

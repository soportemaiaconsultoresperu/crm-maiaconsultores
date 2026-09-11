<?php
namespace App\Enums\Courses;
enum CourseEditionState:string{case Draft='draft';case Scheduled='scheduled';case InProgress='in_progress';case Finished='finished';case Cancelled='cancelled';public function label():string{return match($this){self::Draft=>'Borrador',self::Scheduled=>'Programada',self::InProgress=>'En curso',self::Finished=>'Finalizada',self::Cancelled=>'Cancelada'};}}

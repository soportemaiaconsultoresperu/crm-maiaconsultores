<?php
namespace App\Enums\Courses;
enum CourseEnrollmentState:string{case Enrolled='enrolled';case Confirmed='confirmed';case InProgress='in_progress';case Completed='completed';case Withdrawn='withdrawn';case NoShow='no_show';}

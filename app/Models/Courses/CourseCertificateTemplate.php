<?php
namespace App\Models\Courses;
class CourseCertificateTemplate extends CourseModel{protected $fillable=['name','type_scope','version','is_active','blade_view','settings_json'];protected function casts():array{return ['is_active'=>'boolean','settings_json'=>'array'];}}

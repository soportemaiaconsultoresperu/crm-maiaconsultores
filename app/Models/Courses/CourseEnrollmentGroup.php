<?php
namespace App\Models\Courses;
use App\Models\Customer;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Database\Eloquent\Relations\HasMany;
class CourseEnrollmentGroup extends CourseModel{protected $fillable=['course_edition_id','payer_customer_id','payer_name','payer_document_type','payer_document_number','notes'];public function edition():BelongsTo{return $this->belongsTo(CourseEdition::class,'course_edition_id');}public function payerCustomer():BelongsTo{return $this->belongsTo(Customer::class,'payer_customer_id');}public function enrollments():HasMany{return $this->hasMany(CourseEnrollment::class);}}

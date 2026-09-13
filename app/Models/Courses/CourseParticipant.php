<?php
namespace App\Models\Courses;
use App\Models\Contact;use App\Models\Customer;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Database\Eloquent\Relations\HasMany;
class CourseParticipant extends CourseModel{protected $fillable=['customer_id','contact_id','first_name','last_name','document_type','document_number','email','mobile','document_number_norm','email_norm','mobile_norm'];public function customer():BelongsTo{return $this->belongsTo(Customer::class);}public function contact():BelongsTo{return $this->belongsTo(Contact::class);}public function enrollments():HasMany{return $this->hasMany(CourseEnrollment::class);}}

<?php
namespace App\Models\Courses;
use App\Models\Contact;use App\Models\Customer;use Illuminate\Database\Eloquent\Relations\BelongsTo;use Illuminate\Database\Eloquent\Relations\HasMany;
class CourseParticipant extends CourseModel{protected $fillable=['customer_id','contact_id','first_name','last_name','document_type','document_number','email','mobile','document_number_norm','email_norm','mobile_norm'];public function customer():BelongsTo{return $this->belongsTo(Customer::class);}public function contact():BelongsTo{return $this->belongsTo(Contact::class);}public function enrollments():HasMany{return $this->hasMany(CourseEnrollment::class);}
    /**
     * Document type given to participants built from a CRM contact.
     *
     * The contact may carry no document of its own, so one is fabricated to satisfy the
     * required-document rule. It is an internal key, never a real document, and must not
     * be displayed as if the person had that number.
     */
    public const SYNTHETIC_DOCUMENT_TYPE = 'contact';

    /** The document to show for this participant, or null when there is none to show. */
    public function displayDocument(): ?string
    {
        if ($this->document_type === self::SYNTHETIC_DOCUMENT_TYPE) {
            return null;
        }

        return trim($this->document_type.' '.$this->document_number) ?: null;
    }
}

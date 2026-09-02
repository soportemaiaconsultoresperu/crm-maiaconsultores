<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Primary human contact for a legal prospect before it becomes a customer.
 */
class LeadContact extends Model
{
    use HasAuditColumns, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'lead_id',
        'first_name',
        'last_name',
        'position',
        'phone',
        'whatsapp',
        'email',
        'email_norm',
    ];

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}

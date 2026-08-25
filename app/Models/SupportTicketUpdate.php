<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Model;

class SupportTicketUpdate extends Model
{
    use HasFactory;

    public const TYPE_INTERNAL_NOTE = 'internal_note';
    public const TYPE_CUSTOMER_RESPONSE = 'customer_response';
    public const TYPE_CASE_UPDATE = 'case_update';

    protected $fillable = [
        'ticket_id',
        'type',
        'body',
        'is_internal',
        'is_customer_response',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'is_customer_response' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'docable');
    }
}

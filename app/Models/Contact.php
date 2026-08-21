<?php

namespace App\Models;

use App\Models\Concerns\Automatable;
use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Contact of a customer. Only one active primary contact per customer;
 * reassignment happens transactionally in the service layer.
 */
class Contact extends Model
{
    use Automatable, HasAuditColumns, HasFactory, LogsActivity, SoftDeletes;

    /** @use HasFactory<\Database\Factories\ContactFactory> */

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'first_name',
        'last_name',
        'position',
        'area',
        'phone',
        'whatsapp',
        'email',
        'email_norm',
        'is_primary',
        'is_active',
        'observations',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logAll()
            ->setDescriptionForEvent(fn (string $eventName) => "Contact {$this->first_name} {$this->last_name} was {$eventName}");
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return MorphMany<Document, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'docable');
    }

    /**
     * Scope to active primary contacts.
     *
     * @param  Builder<Contact>  $query
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true)->where('is_active', true);
    }
}

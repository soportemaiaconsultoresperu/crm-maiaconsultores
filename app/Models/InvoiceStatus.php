<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class InvoiceStatus extends Model
{
    /** @use HasFactory<\Database\Factories\InvoiceStatusFactory> */
    use HasAuditColumns, HasFactory, LogsActivity;

    public const SLUG_PAID = 'pagado';
    public const SLUG_OVERDUE = 'vencida';
    public const SLUG_IN_PROCESS = 'en-proceso';
    public const SLUG_CREDIT_NOTE = 'nota-de-credito';

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'sort', 'is_active'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnlyDirty()
            ->logAll()
            ->setDescriptionForEvent(fn (string $eventName) => "Invoice status {$this->slug} was {$eventName}");
    }

    /**
     * @return HasMany<CustomerInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(CustomerInvoice::class, 'status_id');
    }

    /**
     * @return list<string>
     */
    public static function overdueExcludedSlugs(): array
    {
        return [self::SLUG_PAID, self::SLUG_CREDIT_NOTE, self::SLUG_OVERDUE];
    }
}

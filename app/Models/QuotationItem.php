<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Quotation line. Historical copy of unit price and tax name/rate at
 * creation time (ADR-005); later catalog changes never alter it.
 */
class QuotationItem extends Model
{
    use HasAuditColumns, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'quotation_id',
        'product_id',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'discount_amount',
        'tax_id',
        'tax_name',
        'tax_rate',
        'line_subtotal',
        'line_tax',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_subtotal' => 'decimal:2',
            'line_tax' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Tax, $this>
     */
    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class, 'tax_id');
    }
}

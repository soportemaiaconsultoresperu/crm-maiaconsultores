<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

/**
 * Tax catalog (ADR-005). Quotation lines copy name/rate historically,
 * so later catalog changes never alter historical quotations.
 */
class Tax extends Model
{
    use HasAuditColumns;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'rate', 'sort', 'is_active'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}

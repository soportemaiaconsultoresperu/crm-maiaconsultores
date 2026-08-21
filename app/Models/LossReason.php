<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class LossReason extends Model
{
    use HasAuditColumns;

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

    /**
     * @return HasMany<Opportunity, $this>
     */
    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'loss_reason_id');
    }
}

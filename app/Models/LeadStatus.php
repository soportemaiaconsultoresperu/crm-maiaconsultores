<?php

namespace App\Models;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class LeadStatus extends Model
{
    use HasAuditColumns;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'slug', 'sort', 'is_active', 'is_final'];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_active' => 'boolean',
            'is_final' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'status_id');
    }
}

<?php

namespace App\Models\Content;

use App\Traits\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Shared lifecycle for catalogs; historical rows remain readable. */
abstract class ContentCatalog extends Model
{
    use HasAuditColumns, HasFactory;

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Available selections, with stable ordering even when sort values tie. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort')->orderBy('id');
    }

    public function deactivate(): static
    {
        $this->forceFill(['is_active' => false])->save();

        return $this;
    }

    public function reactivate(): static
    {
        $this->forceFill(['is_active' => true])->save();

        return $this;
    }
}

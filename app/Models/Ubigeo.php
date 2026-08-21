<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Model;

/**
 * Official INEI ubigeo catalog, self-referential by level (ADR-009).
 */
class Ubigeo extends Model
{
    /**
     * Singular table name as defined in docs/BASE_DATOS.md §3.9.
     *
     * @var string
     */
    protected $table = 'ubigeo';

    /**
     * The primary key is a non-incrementing 6-char official code
     * (departamento DD0000, provincia DDPP00, distrito DDPPDD).
     *
     * @var string
     */
    protected $primaryKey = 'code';

    /**
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = ['code', 'name', 'level', 'parent_code'];

    /**
     * Parent node: provincia -> departamento, distrito -> provincia.
     *
     * @return HasOne<Ubigeo, $this>
     */
    public function parent(): HasOne
    {
        return $this->hasOne(Ubigeo::class, 'code', 'parent_code');
    }

    /**
     * Direct children of this node.
     *
     * @return HasMany<Ubigeo, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Ubigeo::class, 'parent_code', 'code');
    }

    /**
     * Scope by level.
     *
     * @param  Builder<Ubigeo>  $query
     */
    public function scopeLevel(Builder $query, string $level): Builder
    {
        return $query->where('level', $level);
    }
}

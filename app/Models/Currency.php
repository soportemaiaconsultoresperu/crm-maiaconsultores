<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Currency catalog (ISO 4217, ADR-004). No exchange conversion in v1.
 */
class Currency extends Model
{
    /**
     * Non-incrementing 3-char ISO code primary key.
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
    protected $fillable = ['code', 'name', 'symbol', 'decimals', 'is_active'];

    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}

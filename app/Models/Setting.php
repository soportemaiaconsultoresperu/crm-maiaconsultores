<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * System parameter keyed by name. The "key" column is the primary key
 * (non-incrementing string).
 */
class Setting extends Model
{
    /**
     * @var string
     */
    protected $primaryKey = 'key';

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
    protected $fillable = ['key', 'value', 'type', 'group'];

    protected function casts(): array
    {
        return [
            'type' => 'string',
            'group' => 'string',
        ];
    }
}

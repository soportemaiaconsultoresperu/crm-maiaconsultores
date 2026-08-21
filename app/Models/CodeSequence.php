<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-entity, per-year correlative sequences (ADR-002). Rows are created
 * lazily by CodeGeneratorService inside its locking transaction.
 */
class CodeSequence extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['entity', 'year', 'prefix', 'next_number', 'pad_length'];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'next_number' => 'integer',
            'pad_length' => 'integer',
        ];
    }
}

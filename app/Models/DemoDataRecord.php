<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DemoDataRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'module',
        'table_name',
        'model_type',
        'record_id',
        'created_by',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DemoDataBatch::class, 'batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

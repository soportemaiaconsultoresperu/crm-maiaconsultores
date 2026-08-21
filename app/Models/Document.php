<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Document metadata (ADR-011). Files live on a private disk, never in
 * the database. Soft delete marks the record; the physical file is
 * retained.
 */
class Document extends Model
{
    use SoftDeletes;

    /**
     * uploaded_by / audit columns are filled by the upload service; this
     * model is metadata-only so HasAuditColumns is not required here.
     *
     * @var list<string>
     */
    protected $fillable = [
        'docable_type',
        'docable_id',
        'name',
        'disk',
        'path',
        'mime_type',
        'extension',
        'size_bytes',
        'uploaded_by',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    /**
     * Polymorphic subject: Lead, Customer, Contact, Opportunity,
     * Quotation or Activity.
     *
     * @return MorphTo<Model, $this>
     */
    public function docable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

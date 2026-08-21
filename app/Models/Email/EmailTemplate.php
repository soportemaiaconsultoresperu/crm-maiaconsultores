<?php

declare(strict_types=1);

namespace App\Models\Email;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * B13 — EmailTemplate.
 *
 * Editable email template with a basic versioning scheme. The active row
 * is what the senders read; each publish creates a row in
 * {@see EmailTemplateVersion} for audit. Templates are not executable —
 * per the C-02 architectural rule, content is interpolated against an
 * allow-listed variable set in {@see variables_json}.
 */
class EmailTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\EmailTemplateFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'subject',
        'body_html',
        'body_text',
        'variables_json',
        'is_active',
        'version',
        'owner_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'variables_json' => 'array',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<EmailTemplateVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(EmailTemplateVersion::class, 'template_id')->orderByDesc('version');
    }

    /**
     * The latest version snapshot for this template.
     *
     * @return HasOne<EmailTemplateVersion, $this>
     */
    public function latestVersion(): HasOne
    {
        return $this->hasOne(EmailTemplateVersion::class, 'template_id')->latestOfMany('version');
    }

    /**
     * Messages sent using this template (forward pivot — implementations in
     * Pasada B will hang the relation off `email_messages` once sender
     * columns land).
     *
     * @return HasMany<EmailMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class, 'template_id');
    }

    /**
     * Scope to active templates.
     *
     * @param  Builder<EmailTemplate>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

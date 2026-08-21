<?php

declare(strict_types=1);

namespace App\Models\Email;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * B13 — EmailTemplateVersion.
 *
 * Append-only snapshot of an email template at the moment a new version
 * was published. Carries subject, bodies, variables and the user that
 * took the snapshot (snapshot_by) so audit can answer "who shipped what,
 * when".
 */
class EmailTemplateVersion extends Model
{
    /** @use HasFactory<\Database\Factories\EmailTemplateVersionFactory> */
    use HasFactory;

    /**
     * Append-only — no soft deletes, no update timestamps. The migration
     * declares `created_at`, no `updated_at`.
     */
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'template_id',
        'version',
        'subject',
        'body_html',
        'body_text',
        'variables_json',
        'snapshot_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'variables_json' => 'array',
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EmailTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class, 'template_id');
    }

    /**
     * User that took the snapshot.
     *
     * @return BelongsTo<User, $this>
     */
    public function snapshotter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'snapshot_by');
    }
}

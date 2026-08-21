<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Global tag catalog. Tags attach to any Automatable subject through
 * the `taggables` pivot (polymorphic).
 */
class Tag extends Model
{
    /** @use HasFactory<\Database\Factories\TagFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'color',
    ];

    /**
     * @return MorphMany<Taggable, $this>
     */
    public function taggables(): MorphMany
    {
        return $this->morphMany(Taggable::class, 'taggable');
    }

    /**
     * Resolve a tag by slug or fail loudly.
     */
    public static function bySlug(string $slug): self
    {
        return self::query()->where('slug', $slug)->firstOrFail();
    }

    /**
     * Convenience: case-insensitive slug generator.
     */
    public static function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? $slug;

        return trim($slug, '-');
    }

    /**
     * @param  Builder<Tag>  $query
     */
    public function scopeOfSlug(Builder $query, string $slug): Builder
    {
        return $query->where('slug', $slug);
    }
}
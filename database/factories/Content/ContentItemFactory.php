<?php

namespace Database\Factories\Content;

use App\Enums\Content\ContentItemKind;
use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ContentItem> */
class ContentItemFactory extends Factory
{
    protected $model = ContentItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_project_id' => ContentProjectFactory::new(),
            'kind' => ContentItemKind::Content,
            'planned_at' => fake()->dateTimeBetween('+1 day', '+2 months'),
            'content_pillar_id' => null,
            'content_topic_id' => null,
            'status' => ContentItemStatus::Incomplete,
            'title' => fake()->sentence(4),
            'body' => fake()->paragraph(),
            'hashtags' => null,
            'notes' => null,
        ];
    }

    /** A bare idea: no planned instant, distinguished by `kind`. */
    public function idea(): static
    {
        return $this->state(fn (): array => [
            'kind' => ContentItemKind::Idea,
            'planned_at' => null,
        ]);
    }

    /** A planned content whose instant is already in the past and unpublished. */
    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'kind' => ContentItemKind::Content,
            'planned_at' => now()->subDays(3),
            'status' => ContentItemStatus::Incomplete,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => ContentItemStatus::Published]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\ActionContract;
use App\Models\AutomationExecutionStep;
use App\Models\Tag;
use App\Models\Taggable;
use InvalidArgumentException;

/**
 * Attach a Tag (by slug) to the event subject.
 *
 * Payload:
 *  - tag_slug (required)
 *  - tag_name (optional — used to auto-create when slug not found)
 */
class AddTagAction implements ActionContract
{
    public function execute(array $payload, AutomationExecutionStep $step): void
    {
        $execution = $step->execution()->first();

        if ($execution === null) {
            return;
        }

        $slug = (string) ($payload['tag_slug'] ?? '');

        if ($slug === '') {
            throw new InvalidArgumentException('AddTagAction: tag_slug is required.');
        }

        $tag = Tag::query()->where('slug', $slug)->first();

        if ($tag === null) {
            $name = (string) ($payload['tag_name'] ?? $slug);
            $tag = Tag::query()->create([
                'name' => $name,
                'slug' => $slug,
                'color' => $payload['color'] ?? null,
            ]);
        }

        Taggable::query()->firstOrCreate([
            'tag_id' => $tag->id,
            'taggable_type' => $execution->subject_type,
            'taggable_id' => $execution->subject_id,
        ]);

        $step->response_json = array_merge((array) ($step->response_json ?? []), [
            'tag_id' => $tag->id,
            'tag_slug' => $tag->slug,
        ]);
        $step->save();
    }

    public function simulate(array $payload): array
    {
        return [
            'would_add_tag' => true,
            'payload' => $payload,
        ];
    }
}
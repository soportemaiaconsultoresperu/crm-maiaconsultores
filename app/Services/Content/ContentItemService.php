<?php

namespace App\Services\Content;

use App\Enums\Content\ContentItemKind;
use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFieldSection;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentObjective;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use App\Support\Content\PeruTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read and write surface for a project's contents (T6c).
 *
 * The grid query is built here so the controller stays thin and every listing
 * surface (screen, and later export) filters the same way: only the addressed
 * project's `content` rows, the title/copy search, the month in Peru wall-clock
 * time, and the status/pillar/topic/format/channel narrowings. Nothing here
 * re-derives project reachability — the route binding and the scope service
 * own that, and the query simply pins `content_project_id` to the addressed
 * project so a foreign row can never leak.
 *
 * Writes go through ContentFieldValueService for every custom value, so the
 * T6b type rules and the required-field rule stay in one place, and the whole
 * create/update runs in one transaction: a rejected custom value rolls the
 * item back instead of leaving a half-written content behind.
 */
class ContentItemService
{
    public function __construct(
        private readonly ContentFieldValueService $values = new ContentFieldValueService,
    ) {
    }

    /**
     * The addressed project's contents, with the grid search and filters.
     *
     * @param  array{q?: mixed, month?: mixed, status?: mixed, pillar?: mixed, topic?: mixed, format?: mixed, channel?: mixed}  $filters
     * @return Builder<ContentItem>
     */
    public function query(ContentProject $project, array $filters = []): Builder
    {
        $query = ContentItem::query()
            ->contents()
            ->where('content_project_id', $project->getKey())
            ->with(['pillar', 'topic', 'objectives', 'formats', 'channels']);

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('title', 'like', $like)->orWhere('body', 'like', $like);
            });
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ContentItemStatus::values(), true)) {
            $query->where('status', $status);
        }

        $pillarId = $this->nullableId($filters['pillar'] ?? null);
        if ($pillarId !== null) {
            $query->where('content_pillar_id', $pillarId);
        }

        $topicId = $this->nullableId($filters['topic'] ?? null);
        if ($topicId !== null) {
            $query->where('content_topic_id', $topicId);
        }

        $formatId = $this->nullableId($filters['format'] ?? null);
        if ($formatId !== null) {
            $query->whereHas('formats', fn (Builder $formats): Builder => $formats->whereKey($formatId));
        }

        $channelId = $this->nullableId($filters['channel'] ?? null);
        if ($channelId !== null) {
            $query->whereHas('channels', fn (Builder $channels): Builder => $channels->whereKey($channelId));
        }

        // The month is the Peru calendar month, so its UTC bounds are derived
        // from the IANA zone instead of assuming a fixed offset.
        $month = trim((string) ($filters['month'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            $start = PeruTime::toUtc($month.'-01 00:00:00');
            $query->where('planned_at', '>=', $start)
                ->where('planned_at', '<', $start->addMonthNoOverflow());
        }

        return $query;
    }

    /**
     * The active catalog options the grid filters and the form offer, all
     * scoped to the project.
     *
     * @return array{pillars: \Illuminate\Database\Eloquent\Collection<int, ContentPillar>, topics: \Illuminate\Database\Eloquent\Collection<int, ContentTopic>, objectives: \Illuminate\Database\Eloquent\Collection<int, ContentObjective>, formats: \Illuminate\Database\Eloquent\Collection<int, ContentFormat>, channels: \Illuminate\Database\Eloquent\Collection<int, ContentChannel>}
     */
    public function catalogOptions(ContentProject $project): array
    {
        return [
            'pillars' => ContentPillar::query()
                ->where('content_project_id', $project->getKey())
                ->active()
                ->get(),
            'topics' => ContentTopic::query()
                ->whereHas('pillar', fn (Builder $pillars): Builder => $pillars->where('content_project_id', $project->getKey()))
                ->active()
                ->get(),
            'objectives' => ContentObjective::query()
                ->where('content_project_id', $project->getKey())
                ->active()
                ->get(),
            'formats' => ContentFormat::query()
                ->where('content_project_id', $project->getKey())
                ->active()
                ->get(),
            'channels' => ContentChannel::query()
                ->where('content_project_id', $project->getKey())
                ->active()
                ->get(),
        ];
    }

    /**
     * The project's custom fields as the form needs them: grouped by section
     * (plus the ungrouped ones) and paired with the item's stored values. An
     * inactive field stays visible on an item that already holds a value, so a
     * retired field never hides data.
     *
     * @return array{
     *     sections: \Illuminate\Database\Eloquent\Collection<int, ContentFieldSection>,
     *     fieldsBySection: \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Collection<int, ContentFieldDefinition>>,
     *     ungroupedFields: \Illuminate\Support\Collection<int, ContentFieldDefinition>,
     *     values: array<string, mixed>,
     * }
     */
    public function formData(ContentProject $project, ?ContentItem $item): array
    {
        $values = $item !== null ? $this->values->values($item) : [];

        $definitions = ContentFieldDefinition::query()
            ->where('content_project_id', $project->getKey())
            ->where(function (Builder $query) use ($values): void {
                $query->where('is_active', true);

                if ($values !== []) {
                    $query->orWhereIn('key', array_keys($values));
                }
            })
            ->with(['options' => fn ($options) => $options->orderBy('sort')->orderBy('id')])
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $sections = ContentFieldSection::query()
            ->where('content_project_id', $project->getKey())
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return [
            'sections' => $sections,
            'fieldsBySection' => $definitions->groupBy('content_field_section_id'),
            'ungroupedFields' => $definitions->filter(
                fn (ContentFieldDefinition $field): bool => $field->content_field_section_id === null,
            )->values(),
            'values' => $values,
        ];
    }

    /**
     * Create a planned content for the project, persisting its selections and
     * its custom values in one transaction.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(ContentProject $project, array $data): ContentItem
    {
        return DB::transaction(function () use ($project, $data): ContentItem {
            $item = ContentItem::query()->create([
                'content_project_id' => $project->getKey(),
                'kind' => ContentItemKind::Content,
                'planned_at' => $this->plannedAt($data),
                'content_pillar_id' => $this->nullableId($data['content_pillar_id'] ?? null),
                'content_topic_id' => $this->nullableId($data['content_topic_id'] ?? null),
                'status' => $data['status'],
                'title' => $data['title'],
                'body' => $data['body'],
                'hashtags' => $this->nullableString($data['hashtags'] ?? null),
                'notes' => $this->nullableString($data['notes'] ?? null),
            ]);

            $this->syncSelections($item, $data);
            $this->saveCustomValues($item, $data['fields'] ?? []);

            return $item;
        });
    }

    /**
     * Update an existing content, selections and custom values included. The
     * whole write is transactional for the same reason as create().
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(ContentItem $item, array $data): ContentItem
    {
        return DB::transaction(function () use ($item, $data): ContentItem {
            $item->fill([
                'planned_at' => $this->plannedAt($data),
                'content_pillar_id' => $this->nullableId($data['content_pillar_id'] ?? null),
                'content_topic_id' => $this->nullableId($data['content_topic_id'] ?? null),
                'status' => $data['status'],
                'title' => $data['title'],
                'body' => $data['body'],
                'hashtags' => $this->nullableString($data['hashtags'] ?? null),
                'notes' => $this->nullableString($data['notes'] ?? null),
            ]);
            $item->save();

            $this->syncSelections($item, $data);
            $this->saveCustomValues($item, $data['fields'] ?? []);

            return $item;
        });
    }

    /**
     * Persist the multi-select relations. Ownership and active-state checks are
     * the FormRequest's job through ContentItemSelectionService.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncSelections(ContentItem $item, array $data): void
    {
        $item->objectives()->sync($this->ids($data['objectives'] ?? []));
        $item->formats()->sync($this->ids($data['formats'] ?? []));
        $item->channels()->sync($this->ids($data['channels'] ?? []));
    }

    /**
     * Route every custom value through ContentFieldValueService. A service
     * refusal is re-keyed to `fields.<key>` so the form shows it next to the
     * offending input instead of under a bare field key.
     *
     * @param  array<string, mixed>  $fields
     *
     * @throws ValidationException
     */
    private function saveCustomValues(ContentItem $item, array $fields): void
    {
        try {
            $this->values->save($item, $fields);
        } catch (ValidationException $exception) {
            $prefixed = [];

            foreach ($exception->errors() as $key => $messages) {
                $prefixed['fields.'.$key] = $messages;
            }

            throw ValidationException::withMessages($prefixed);
        }
    }

    /**
     * Combine the Peru date and time the form submits into the UTC instant that
     * is stored. A blank date yields a null instant (an undated row).
     *
     * @param  array<string, mixed>  $data
     */
    private function plannedAt(array $data): ?CarbonImmutable
    {
        $date = trim((string) ($data['planned_date'] ?? ''));

        if ($date === '') {
            return null;
        }

        $time = trim((string) ($data['planned_time'] ?? ''));

        return PeruTime::toUtc($date.' '.($time === '' ? '00:00' : $time));
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}

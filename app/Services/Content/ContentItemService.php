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
        private readonly ContentAttachmentService $attachments = new ContentAttachmentService,
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
     * whole write is transactional for the same reason as create(). The caller
     * submits the revision the form loaded; a stale revision refuses the write
     * (T8a) and a successful save increments it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(ContentItem $item, array $data): ContentItem
    {
        return DB::transaction(function () use ($item, $data): ContentItem {
            $locked = ContentItem::query()->lockForUpdate()->findOrFail($item->getKey());
            $this->assertCurrentRevision($locked, $data['revision'] ?? null);

            $locked->fill([
                'planned_at' => $this->plannedAt($data),
                'content_pillar_id' => $this->nullableId($data['content_pillar_id'] ?? null),
                'content_topic_id' => $this->nullableId($data['content_topic_id'] ?? null),
                'status' => $data['status'],
                'title' => $data['title'],
                'body' => $data['body'],
                'hashtags' => $this->nullableString($data['hashtags'] ?? null),
                'notes' => $this->nullableString($data['notes'] ?? null),
            ]);
            $locked->revision = ((int) $locked->revision) + 1;
            $locked->save();

            $this->syncSelections($locked, $data);
            $this->saveCustomValues($locked, $data['fields'] ?? []);

            return $locked;
        });
    }

    /**
     * Duplicate a content into a new, independent row (T8a).
     *
     * The copy carries the source's text, pillar, topic, multi-select
     * selections, typed custom values and attachments, but it deliberately gets
     * no planned instant, is reset to the `Incompleto` status and keeps the
     * `content` kind rather than becoming an idea; its title is suffixed so an
     * operator can tell the two rows apart. The source is never modified.
     *
     * Every uploaded attachment is physically copied to its own path on the
     * private disk, so retiring or removing one content's file never touches
     * the other's. The whole operation runs in one transaction, and if anything
     * fails after a file was already copied, that file is removed before the
     * failure propagates: the result is neither a half-copied content nor an
     * orphan file.
     *
     * @throws ValidationException
     */
    public function duplicate(ContentItem $source): ContentItem
    {
        /** @var list<string> $copiedFiles */
        $copiedFiles = [];

        try {
            return DB::transaction(function () use ($source, &$copiedFiles): ContentItem {
                $original = ContentItem::query()
                    ->lockForUpdate()
                    ->findOrFail($source->getKey())
                    ->load(['objectives', 'formats', 'channels', 'attachments']);

                $copy = ContentItem::query()->create([
                    'content_project_id' => $original->content_project_id,
                    'kind' => ContentItemKind::Content,
                    'planned_at' => null,
                    'content_pillar_id' => $original->content_pillar_id,
                    'content_topic_id' => $original->content_topic_id,
                    'status' => ContentItemStatus::Incomplete,
                    'title' => $this->duplicateTitle((string) $original->title),
                    'body' => $original->body,
                    'hashtags' => $original->hashtags,
                    'notes' => $original->notes,
                ]);

                $copy->objectives()->sync($original->objectives->pluck('id')->all());
                $copy->formats()->sync($original->formats->pluck('id')->all());
                $copy->channels()->sync($original->channels->pluck('id')->all());

                $this->saveCustomValues($copy, $this->values->values($original));

                foreach ($original->attachments as $attachment) {
                    $created = $this->attachments->copyOnto($copy, $attachment);

                    if ($created->path !== null && $created->path !== '') {
                        $copiedFiles[] = $created->path;
                    }
                }

                return $copy;
            });
        } catch (\Throwable $exception) {
            foreach ($copiedFiles as $path) {
                $this->attachments->discardStoredFile($path);
            }

            throw $exception;
        }
    }

    /**
     * Change a content's planned instant to another Peru wall-clock date and
     * time, storing the matching UTC instant (T8a). The submitted revision must
     * still be the stored one, so a reschedule cannot silently undo a
     * concurrent edit; a successful reschedule increments it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function reschedule(ContentItem $item, array $data): ContentItem
    {
        return DB::transaction(function () use ($item, $data): ContentItem {
            $locked = ContentItem::query()->lockForUpdate()->findOrFail($item->getKey());
            $this->assertCurrentRevision($locked, $data['revision'] ?? null);

            $locked->planned_at = $this->plannedAt($data);
            $locked->revision = ((int) $locked->revision) + 1;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Refuse a save whose revision no longer matches the stored one, with a
     * Spanish message telling the user the record changed since they opened it.
     * Running inside the write transaction after `lockForUpdate` is what makes
     * this race-free: the losing writer never overwrites the winner's row.
     *
     * @throws ValidationException
     */
    private function assertCurrentRevision(ContentItem $item, mixed $expected): void
    {
        if ($expected === null || (int) $expected !== (int) $item->revision) {
            throw ValidationException::withMessages([
                'revision' => 'El contenido cambió desde que lo abrió. No se guardaron sus cambios para no sobrescribir el trabajo de otra persona. Vuelva a abrirlo para ver la versión más reciente.',
            ]);
        }
    }

    /**
     * Mark the copy so an operator can tell it apart from the source, keeping
     * the 200-character column limit.
     */
    private function duplicateTitle(string $title): string
    {
        $suffix = ' (copia)';

        return mb_substr($title, 0, 200 - mb_strlen($suffix)).$suffix;
    }

    /**
     * The addressed project's undated ideas, newest first. Same pinning as the
     * grid query: only this project's `idea` rows, never a foreign one.
     *
     * @return Builder<ContentItem>
     */
    public function ideasQuery(ContentProject $project): Builder
    {
        return ContentItem::query()
            ->ideas()
            ->where('content_project_id', $project->getKey())
            ->with(['pillar', 'topic', 'objectives', 'formats', 'channels']);
    }

    /**
     * Register a bare idea: no planned instant and the default editorial status,
     * with its selections and custom values stored in one transaction.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createIdea(ContentProject $project, array $data): ContentItem
    {
        return DB::transaction(function () use ($project, $data): ContentItem {
            $idea = ContentItem::query()->create([
                'content_project_id' => $project->getKey(),
                'kind' => ContentItemKind::Idea,
                'planned_at' => null,
                'content_pillar_id' => $this->nullableId($data['content_pillar_id'] ?? null),
                'content_topic_id' => $this->nullableId($data['content_topic_id'] ?? null),
                'status' => ContentItemStatus::Incomplete,
                'title' => $data['title'],
                'body' => $data['body'],
                'hashtags' => $this->nullableString($data['hashtags'] ?? null),
                'notes' => $this->nullableString($data['notes'] ?? null),
            ]);

            $this->syncSelections($idea, $data);
            $this->saveCustomValues($idea, $data['fields'] ?? []);

            return $idea;
        });
    }

    /**
     * Edit an idea. `kind`, `planned_at` and `status` are deliberately not
     * touched: an idea stays dateless and its editorial status only ever changes
     * through conversion, which preserves it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function updateIdea(ContentItem $idea, array $data): ContentItem
    {
        return DB::transaction(function () use ($idea, $data): ContentItem {
            $idea->fill([
                'content_pillar_id' => $this->nullableId($data['content_pillar_id'] ?? null),
                'content_topic_id' => $this->nullableId($data['content_topic_id'] ?? null),
                'title' => $data['title'],
                'body' => $data['body'],
                'hashtags' => $this->nullableString($data['hashtags'] ?? null),
                'notes' => $this->nullableString($data['notes'] ?? null),
            ]);
            $idea->save();

            $this->syncSelections($idea, $data);
            $this->saveCustomValues($idea, $data['fields'] ?? []);

            return $idea;
        });
    }

    /**
     * Convert an idea into a planned content, atomically and without
     * duplicating it.
     *
     * The conversion is an in-place update of the SAME row: `kind` becomes
     * `content` and `planned_at` becomes the submitted Peruvian instant. Every
     * other column, the selections pivots and the custom-field rows are already
     * on that row, so nothing has to be copied and no second content can be
     * created. The whole operation runs in one transaction with `lockForUpdate`,
     * so two concurrent requests cannot both convert the row: the loser reads a
     * `content` and is refused.
     *
     * The row's status is intentionally not changed. The specification defines
     * no status transition for conversion and the confirmed decisions only list
     * text, notes, hashtags, pillar, topic, selections and custom values as data
     * that must survive; inventing a status change would be an invented
     * transition. A new idea is stored `Incompleto`, which is also the sensible
     * state for a freshly converted, not yet produced content.
     *
     * A required custom field that lost its value blocks the conversion before
     * anything is written, and any failure inside the transaction rolls the row
     * back, so a rejected conversion leaves the idea exactly as it was.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function convertIdea(ContentItem $idea, array $data): ContentItem
    {
        return DB::transaction(function () use ($idea, $data): ContentItem {
            $locked = ContentItem::query()->lockForUpdate()->findOrFail($idea->getKey());

            if (! $locked->isIdea()) {
                throw ValidationException::withMessages([
                    'planned_date' => 'La idea ya fue convertida en contenido.',
                ]);
            }

            // Re-assert the content contract before mutating: a required custom
            // field with no stored value refuses the conversion, so the idea is
            // left untouched and unchanged.
            $this->saveCustomValues($locked, []);

            $locked->fill([
                'kind' => ContentItemKind::Content,
                'planned_at' => PeruTime::toUtc($data['planned_date'].' '.$data['planned_time']),
            ]);
            $locked->save();

            return $locked;
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

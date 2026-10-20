<?php

namespace App\Services\Content;

use App\Enums\Content\ContentItemKind;
use App\Enums\Content\ContentItemStatus;
use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFieldOption;
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
    /**
     * A grid month: `YYYY-MM` with a real month (01-12). Shared with the filter
     * request so the screen, the exports and the query agree on what is valid.
     */
    public const MONTH_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

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

        // `%` and `_` typed by the user are literal characters, not wildcards,
        // so they are escaped with an explicit ESCAPE character that SQLite
        // and MySQL both honour.
        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->whereRaw("title LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("body LIKE ? ESCAPE '!'", [$like]);
            });
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ContentItemStatus::values(), true)) {
            $query->where('status', $status);
        }

        // A filter on a standard field the project hides is ignored (S2), so a
        // stale URL can never narrow the grid or an export by an invisible value.
        $hidden = $project->hiddenStandardFields();
        $filterValue = fn (string $filter, string $field): ?int => in_array($field, $hidden, true)
            ? null
            : $this->nullableId($filters[$filter] ?? null);

        $pillarId = $filterValue('pillar', 'pillar');
        if ($pillarId !== null) {
            $query->where('content_pillar_id', $pillarId);
        }

        $topicId = $filterValue('topic', 'topic');
        if ($topicId !== null) {
            $query->where('content_topic_id', $topicId);
        }

        $formatId = $filterValue('format', 'formats');
        if ($formatId !== null) {
            $query->whereHas('formats', fn (Builder $formats): Builder => $formats->whereKey($formatId));
        }

        $channelId = $filterValue('channel', 'channels');
        if ($channelId !== null) {
            $query->whereHas('channels', fn (Builder $channels): Builder => $channels->whereKey($channelId));
        }

        // The month is the Peru calendar month, so its UTC bounds are derived
        // from the IANA zone instead of assuming a fixed offset. An impossible
        // month (13, 00) is ignored here; the filter request reports it.
        $month = trim((string) ($filters['month'] ?? ''));
        if (preg_match(self::MONTH_PATTERN, $month) === 1) {
            $start = PeruTime::toUtc($month.'-01 00:00:00');
            $query->where('planned_at', '>=', $start)
                ->where('planned_at', '<', $start->addMonthNoOverflow());
        }

        return $query;
    }

    /**
     * The catalog options the grid filters and the form offer, all scoped to
     * the project.
     *
     * Only active options are offered, except that an edited row also gets the
     * retired options it already stores, so its form can keep them selected
     * (the views mark them "(Retirada)"). Without them a re-submitted form would
     * silently drop a historical reference. The selection guard still refuses a
     * retired option the row did not already hold.
     *
     * @return array{pillars: \Illuminate\Database\Eloquent\Collection<int, ContentPillar>, topics: \Illuminate\Database\Eloquent\Collection<int, ContentTopic>, objectives: \Illuminate\Database\Eloquent\Collection<int, ContentObjective>, formats: \Illuminate\Database\Eloquent\Collection<int, ContentFormat>, channels: \Illuminate\Database\Eloquent\Collection<int, ContentChannel>}
     */
    public function catalogOptions(ContentProject $project, ?ContentItem $item = null): array
    {
        $stored = fn (string $relation): array => $item === null
            ? []
            : $item->{$relation}()
                ->pluck($item->{$relation}()->getRelated()->getQualifiedKeyName())
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

        $topics = ContentTopic::query()
            ->whereHas('pillar', fn (Builder $pillars): Builder => $pillars->where('content_project_id', $project->getKey()));

        return [
            'pillars' => $this->activeOrStored(
                ContentPillar::query()->where('content_project_id', $project->getKey()),
                $item?->content_pillar_id !== null ? [(int) $item->content_pillar_id] : [],
            ),
            'topics' => $this->activeOrStored(
                $topics,
                $item?->content_topic_id !== null ? [(int) $item->content_topic_id] : [],
            ),
            'objectives' => $this->activeOrStored(
                ContentObjective::query()->where('content_project_id', $project->getKey()),
                $stored('objectives'),
            ),
            'formats' => $this->activeOrStored(
                ContentFormat::query()->where('content_project_id', $project->getKey()),
                $stored('formats'),
            ),
            'channels' => $this->activeOrStored(
                ContentChannel::query()->where('content_project_id', $project->getKey()),
                $stored('channels'),
            ),
        ];
    }

    /**
     * Active options plus the given stored ids, in catalog order.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $storedIds
     * @return \Illuminate\Database\Eloquent\Collection<int, TModel>
     */
    private function activeOrStored(Builder $query, array $storedIds): \Illuminate\Database\Eloquent\Collection
    {
        $table = $query->getModel()->getTable();

        return $query
            ->where(function (Builder $inner) use ($table, $storedIds): void {
                $inner->where($table.'.is_active', true);

                if ($storedIds !== []) {
                    $inner->orWhereIn($table.'.id', $storedIds);
                }
            })
            ->orderBy($table.'.sort')
            ->orderBy($table.'.id')
            ->get();
    }

    /**
     * The project's custom fields as the form needs them: grouped by section
     * (plus the ungrouped ones) and paired with the item's stored values. An
     * inactive field stays visible on an item that already holds a value, so a
     * retired field never hides data. Selection options follow the catalog
     * rule: an inactive option is offered only to the item that already stores
     * it (the views mark it "(Retirada)"), never to a new content or idea.
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

        foreach ($definitions as $definition) {
            $storedOptionIds = array_map('intval', (array) ($values[$definition->key] ?? []));
            $definition->setRelation('options', $definition->options->filter(
                fn (ContentFieldOption $option): bool => $option->is_active || in_array((int) $option->id, $storedOptionIds, true),
            )->values());
        }

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
                'status' => $data['status'],
                'title' => $data['title'],
                'body' => $data['body'],
            ] + $this->optionalColumns($project, $data));

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
                'status' => $data['status'],
                'title' => $data['title'],
                'body' => $data['body'],
            ] + $this->optionalColumns($locked->project, $data));
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

                // Copy what the source holds, as it is: a required field added
                // after the source was created must not block its duplicate.
                $this->saveCustomValues($copy, $this->values->values($original), enforceRequired: false);

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
     * grid query: only this project's `idea` rows, never a foreign one. The
     * model's planned-date ordering means nothing for dateless rows, so it is
     * replaced by creation order, most recent on top.
     *
     * @return Builder<ContentItem>
     */
    public function ideasQuery(ContentProject $project): Builder
    {
        return ContentItem::query()
            ->withoutGlobalScope('ordered')
            ->ideas()
            ->where('content_project_id', $project->getKey())
            ->with(['pillar', 'topic', 'objectives', 'formats', 'channels'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
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
                'status' => ContentItemStatus::Incomplete,
                'title' => $data['title'],
                'body' => $data['body'],
            ] + $this->optionalColumns($project, $data));

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
     * Same concurrent-edit protection as update(): the row is locked, a stale
     * revision refuses the write and a successful save increments it.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function updateIdea(ContentItem $idea, array $data): ContentItem
    {
        return DB::transaction(function () use ($idea, $data): ContentItem {
            $idea = ContentItem::query()->lockForUpdate()->findOrFail($idea->getKey());
            $this->assertCurrentRevision($idea, $data['revision'] ?? null);

            $idea->revision = ((int) $idea->revision) + 1;
            $idea->fill([
                'title' => $data['title'],
                'body' => $data['body'],
            ] + $this->optionalColumns($idea->project, $data));
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
        $hidden = $item->project?->hiddenStandardFields() ?? [];

        // A relation the project hides keeps its stored rows untouched (S2).
        foreach (['objectives', 'formats', 'channels'] as $relation) {
            if (! in_array($relation, $hidden, true)) {
                $item->{$relation}()->sync($this->ids($data[$relation] ?? []));
            }
        }
    }

    /**
     * The optional standard columns to write. A column the project hides is
     * left out, so a new row stores nothing for it and an edited row keeps its
     * stored value untouched (S2): hiding a field never deletes data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function optionalColumns(?ContentProject $project, array $data): array
    {
        $columns = [
            'content_pillar_id' => $this->nullableId($data['content_pillar_id'] ?? null),
            'content_topic_id' => $this->nullableId($data['content_topic_id'] ?? null),
            'hashtags' => $this->nullableString($data['hashtags'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
        ];

        return array_diff_key($columns, array_flip($project?->hiddenStandardInputs() ?? []));
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
    private function saveCustomValues(ContentItem $item, array $fields, bool $enforceRequired = true): void
    {
        try {
            $this->values->save($item, $fields, $enforceRequired);
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

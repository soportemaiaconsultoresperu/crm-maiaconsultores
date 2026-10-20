<?php

namespace App\Services\Content;

use App\Enums\Content\ContentFieldType;
use App\Enums\Content\ContentItemStatus;
use App\Exports\Content\ContentCellSanitizer;
use App\Models\Content\ContentChannel;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFormat;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentPillar;
use App\Models\Content\ContentProject;
use App\Models\Content\ContentTopic;
use App\Support\Content\PeruTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Content scheduling module — exports (T10).
 *
 * Builds the exported grid from the SAME filtered query the screen uses
 * (`ContentItemService::query()`), so the exported set can never drift from what
 * the operator sees: same project pin, same title/copy search, same month,
 * status and catalog narrowings. Custom-field columns come from the project's
 * active definitions plus any inactive definition that still holds data
 * (labelled " (inactivo)"), and their values are read through
 * ContentFieldValueService, the single value reader of the module.
 *
 * Dates are on the Peru clock (Fecha d/m/Y, Horario H:i) and the "Atrasado"
 * column asks the model's own overdue rule, so the export never re-derives it.
 *
 * The project pin lives in `ContentItemService::query()`, so even a filter input
 * that names another project's catalog id can only narrow this project's rows,
 * never widen the export across projects.
 *
 * A standard field the project hides (S2) has no column in either export, so
 * the exported columns mirror the grid's; the stored data is untouched.
 *
 * Every cell goes through ContentCellSanitizer before it can reach a
 * spreadsheet: headings (custom field labels are user text), grid text,
 * catalog names and custom values alike.
 */
class ContentGridExportService
{
    /**
     * Grid columns, in the on-screen order, each paired with the hideable
     * standard field it shows (null for a column that can never be hidden).
     * The Acciones column is not data.
     *
     * @var list<array{0: string, 1: ?string}>
     */
    private const GRID_COLUMNS = [
        ['Fecha', null],
        ['Día', null],
        ['Horario', null],
        ['Pilar de contenido', 'pillar'],
        ['Tema', 'topic'],
        ['Estado', null],
        ['Atrasado', null],
        ['Objetivos', 'objectives'],
        ['Formato', 'formats'],
        ['Red social o canal', 'channels'],
        ['Gancho o título', null],
        ['Descripción o copy', null],
        ['Hashtags', 'hashtags'],
        ['Notas', 'notes'],
    ];

    public function __construct(
        private readonly ContentItemService $items,
        private readonly ContentFieldValueService $values,
    ) {
    }

    /**
     * The grid columns followed by one column per exported custom field of the
     * project, neutralised like any other cell because a label is user text.
     *
     * @return list<string>
     */
    public function headings(ContentProject $project): array
    {
        $labels = $this->definitions($project)
            ->map(fn (ContentFieldDefinition $definition): string => $definition->is_active
                ? (string) $definition->label
                : $definition->label.' (inactivo)')
            ->all();

        return array_map(
            [ContentCellSanitizer::class, 'sanitize'],
            array_merge(array_column($this->gridColumns($project), 0), $labels),
        );
    }

    /**
     * How many rows an export with these filters would contain, so the caller
     * can refuse an oversized export before loading it.
     *
     * @param  array<string, string>  $filters
     */
    public function count(ContentProject $project, array $filters): int
    {
        return $this->items->query($project, $filters)->count();
    }

    /**
     * The exported rows: the addressed project's contents matching the same
     * filters the grid accepts, with their custom values appended and every cell
     * made spreadsheet-safe.
     *
     * @param  array<string, string>  $filters
     * @return list<list<mixed>>
     */
    public function rows(ContentProject $project, array $filters): array
    {
        $definitions = $this->definitions($project);

        return $this->items
            ->query($project, $filters)
            ->with(['fieldValues.definition', 'fieldValues.selectedOptions'])
            ->get()
            ->map(fn (ContentItem $item): array => $this->mapRow($project, $item, $definitions))
            ->all();
    }

    /**
     * The applied filters as Spanish statements for the exported document, so a
     * PDF states the slice it represents. Catalog ids are resolved to their
     * names inside the addressed project; an unresolved id falls back to the raw
     * value instead of leaking a foreign name.
     *
     * @param  array<string, string>  $filters
     * @return list<string>
     */
    public function filterSummary(ContentProject $project, array $filters): array
    {
        $summary = [];

        $term = trim((string) ($filters['q'] ?? ''));
        if ($term !== '') {
            $summary[] = 'Buscar: '.$term;
        }

        $month = trim((string) ($filters['month'] ?? ''));
        if ($month !== '') {
            $summary[] = 'Mes: '.$month;
        }

        $range = $this->rangeStatement($filters);
        if ($range !== null) {
            $summary[] = $range;
        }

        $status = (string) ($filters['status'] ?? '');
        if (in_array($status, ContentItemStatus::values(), true)) {
            $summary[] = 'Estado: '.(ContentItemStatus::labels()[$status] ?? $status);
        }

        // A filter on a hidden field is ignored by the query, so it is not stated.
        $catalogs = [
            'pillar' => ['Pilar de contenido', ContentPillar::class, 'pillar'],
            'topic' => ['Tema', ContentTopic::class, 'topic'],
            'format' => ['Formato', ContentFormat::class, 'formats'],
            'channel' => ['Red social o canal', ContentChannel::class, 'channels'],
        ];

        foreach ($catalogs as $key => [$label, $class, $field]) {
            $id = (int) ($filters[$key] ?? 0);

            if ($id <= 0 || $project->isStandardFieldHidden($field)) {
                continue;
            }

            $summary[] = $label.': '.($this->catalogName($class, $id, $project) ?? (string) $id);
        }

        return $summary;
    }

    /**
     * "Desde dd/mm/yyyy hasta dd/mm/yyyy" (or just one bound) for a valid
     * Desde/Hasta filter, null when no valid bound is applied.
     *
     * @param  array<string, string>  $filters
     */
    private function rangeStatement(array $filters): ?string
    {
        $bound = function (string $key) use ($filters): ?string {
            $value = trim((string) ($filters[$key] ?? ''));

            return ContentItemService::isRangeDate($value)
                ? CarbonImmutable::createFromFormat('Y-m-d', $value)->format('d/m/Y')
                : null;
        };

        $from = $bound('from');
        $to = $bound('to');

        return match (true) {
            $from !== null && $to !== null => 'Desde '.$from.' hasta '.$to,
            $from !== null => 'Desde '.$from,
            $to !== null => 'Hasta '.$to,
            default => null,
        };
    }

    /**
     * The grid columns the project shows: every column whose standard field is
     * not hidden.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private function gridColumns(ContentProject $project): array
    {
        return array_values(array_filter(
            self::GRID_COLUMNS,
            fn (array $column): bool => $column[1] === null || ! $project->isStandardFieldHidden($column[1]),
        ));
    }

    /**
     * The project's active custom fields plus the inactive ones that still hold
     * a stored value, grouped by their section and then by their own order, so
     * the extra export columns read in the same order the editor presents them.
     * Ungrouped fields come last. A retired field without data adds no column.
     *
     * @return Collection<int, ContentFieldDefinition>
     */
    private function definitions(ContentProject $project): Collection
    {
        return ContentFieldDefinition::query()
            ->where('content_project_id', $project->getKey())
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->orWhereHas('values'))
            ->with(['section', 'options' => fn ($options) => $options->orderBy('sort')->orderBy('id')])
            ->get()
            ->sort(function (ContentFieldDefinition $a, ContentFieldDefinition $b): int {
                return [
                    $a->section?->sort ?? PHP_INT_MAX,
                    $a->section?->getKey() ?? PHP_INT_MAX,
                    $a->sort,
                    $a->getKey(),
                ] <=> [
                    $b->section?->sort ?? PHP_INT_MAX,
                    $b->section?->getKey() ?? PHP_INT_MAX,
                    $b->sort,
                    $b->getKey(),
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, ContentFieldDefinition>  $definitions
     * @return list<mixed>
     */
    private function mapRow(ContentProject $project, ContentItem $item, Collection $definitions): array
    {
        $planned = $item->planned_at !== null ? PeruTime::toPeru($item->planned_at) : null;
        $values = $this->values->values($item);

        $cells = [
            'Fecha' => $planned?->format('d/m/Y') ?? '—',
            'Día' => $planned !== null ? PeruTime::weekdayLabel($item->planned_at) : '—',
            'Horario' => $planned?->format('H:i') ?? '—',
            'Pilar de contenido' => $item->pillar?->name ?? '—',
            'Tema' => $item->topic?->name ?? '—',
            'Estado' => $item->status->label(),
            'Atrasado' => $item->isOverdue() ? 'Atrasado' : '',
            'Objetivos' => $this->joinNames($item->objectives),
            'Formato' => $this->joinNames($item->formats),
            'Red social o canal' => $this->joinNames($item->channels),
            'Gancho o título' => $item->title,
            'Descripción o copy' => $item->body,
            'Hashtags' => $item->hashtags ?? '—',
            'Notas' => $item->notes ?? '—',
        ];

        $row = array_map(
            fn (array $column): mixed => $cells[$column[0]],
            $this->gridColumns($project),
        );

        foreach ($definitions as $definition) {
            $row[] = $this->fieldValue($definition, $values[$definition->key] ?? null);
        }

        return array_map([ContentCellSanitizer::class, 'sanitize'], $row);
    }

    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $models
     */
    private function joinNames(Collection $models): string
    {
        $names = $models->pluck('name')->filter()->implode(', ');

        return $names !== '' ? $names : '—';
    }

    /**
     * Present one custom value in its human shape. An absent value is the same
     * em dash the grid uses, so an empty cell is never read as data.
     */
    private function fieldValue(ContentFieldDefinition $definition, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            return '—';
        }

        return match ($definition->type) {
            ContentFieldType::Text, ContentFieldType::LongText => (string) $value,
            ContentFieldType::Number => (float) $value,
            ContentFieldType::Date => CarbonImmutable::parse((string) $value)->format('d/m/Y'),
            ContentFieldType::SingleSelection => $this->optionLabel($definition, (int) $value),
            ContentFieldType::MultipleSelection => $this->optionLabels($definition, (array) $value),
        };
    }

    private function optionLabel(ContentFieldDefinition $definition, int $optionId): string
    {
        return $definition->options->firstWhere('id', $optionId)?->label ?? '—';
    }

    /**
     * @param  array<int, mixed>  $optionIds
     */
    private function optionLabels(ContentFieldDefinition $definition, array $optionIds): string
    {
        $labels = collect($optionIds)
            ->map(fn (mixed $id): ?string => $definition->options->firstWhere('id', (int) $id)?->label)
            ->filter()
            ->implode(', ');

        return $labels !== '' ? $labels : '—';
    }

    /**
     * Resolve a catalog id to its name only inside the addressed project, so a
     * foreign or unknown id renders as itself and never discloses a foreign row.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class
     */
    private function catalogName(string $class, int $id, ContentProject $project): ?string
    {
        $query = $class::query()->whereKey($id);

        if ($class === ContentTopic::class) {
            $query->whereHas('pillar', fn ($pillars) => $pillars->where('content_project_id', $project->getKey()));
        } else {
            $query->where('content_project_id', $project->getKey());
        }

        $name = $query->value('name');

        return $name !== null ? (string) $name : null;
    }
}

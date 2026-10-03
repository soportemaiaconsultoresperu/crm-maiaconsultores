<?php

namespace App\Services\Content;

use App\Enums\Content\ContentFieldType;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFieldOption;
use App\Models\Content\ContentFieldValue;
use App\Models\Content\ContentItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Server-side value store for a content item's custom fields (T6b).
 *
 * Values are read and written from one array keyed by the definition's stable
 * `key`, so a label rename never touches stored data. Everything a value must
 * satisfy lives here, in one place:
 * - a definition must belong to the item's own project;
 * - a value must satisfy the rules its ContentFieldType declares, including
 *   the two list types;
 * - a required field must hold a non-empty value;
 * - a single-selection option and every multiple-selection option must belong
 *   to that exact definition, narrowing the T5 table-level vocabulary rule;
 * - an empty entry clears the stored row instead of persisting a blank one.
 *
 * The service is the only writer of `content_field_values`; callers never write
 * the model directly.
 */
class ContentFieldValueService
{
    /**
     * Read a content item's stored values, keyed by each definition's `key`.
     * Only fields that currently hold a value are returned.
     *
     * @return array<string, mixed>
     */
    public function values(ContentItem $item): array
    {
        if (! $item->exists) {
            return [];
        }

        // An eager-loaded caller (the grid exports, which map many rows in one
        // pass) already holds the rows with their definition and selected
        // options, so reading them again would be a needless query per row.
        $rows = $item->relationLoaded('fieldValues')
            ? $item->fieldValues
            : ContentFieldValue::query()
                ->where('content_item_id', $item->getKey())
                ->with(['definition', 'selectedOptions'])
                ->get();

        return $this->mapValues($rows);
    }

    /**
     * Turn stored value rows into the `key => value` map, keyed by each
     * definition's stable `key` and skipping rows whose definition is gone.
     *
     * @param  iterable<int, ContentFieldValue>  $rows
     * @return array<string, mixed>
     */
    private function mapValues(iterable $rows): array
    {
        $values = [];

        foreach ($rows as $row) {
            $definition = $row->definition;

            if ($definition === null) {
                continue;
            }

            $values[$definition->key] = $this->readValue($definition, $row);
        }

        return $values;
    }

    /**
     * Write a content item's custom values from an array keyed by field. A
     * present value is validated and stored; a null/blank/empty entry clears
     * the row. Required fields must have a value either in the payload or
     * already stored.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function save(ContentItem $item, array $values): void
    {
        $definitions = $this->definitionsFor($item);

        DB::transaction(function () use ($item, $values, $definitions): void {
            $handled = [];

            foreach ($values as $key => $value) {
                $key = (string) $key;
                $definition = $definitions->get($key);

                if ($definition === null) {
                    throw ValidationException::withMessages([
                        $key => 'El campo no pertenece al proyecto del contenido.',
                    ]);
                }

                $handled[$key] = true;
                $this->assertDefinitionBelongsToItem($item, $definition);

                if ($this->isEmpty($value)) {
                    if ($definition->required && $definition->is_active) {
                        throw ValidationException::withMessages([
                            $key => 'El campo es obligatorio.',
                        ]);
                    }

                    $this->clear($item, $definition);

                    continue;
                }

                $this->store($item, $definition, $value);
            }

            foreach ($definitions as $definition) {
                if (! $definition->required || ! $definition->is_active || isset($handled[$definition->key])) {
                    continue;
                }

                if (! $this->hasStoredValue($item, $definition)) {
                    throw ValidationException::withMessages([
                        $definition->key => 'El campo es obligatorio.',
                    ]);
                }
            }
        });
    }

    /**
     * Validate and persist one value for one definition. The definition must
     * belong to the item's project and the value must satisfy its type rules.
     *
     * @throws ValidationException
     */
    public function store(ContentItem $item, ContentFieldDefinition $definition, mixed $value): ContentFieldValue
    {
        $this->assertDefinitionBelongsToItem($item, $definition);
        $this->assertValidValue($definition, $value);

        $row = ContentFieldValue::query()->firstOrNew([
            'content_item_id' => $item->getKey(),
            'content_field_definition_id' => $definition->getKey(),
        ]);

        // Reset every storage column so a row never keeps a value of the shape
        // it no longer is (a definition's type is immutable once it has values).
        $row->fill([
            'value_text' => null,
            'value_number' => null,
            'value_date' => null,
            'content_field_option_id' => null,
        ]);
        $row->save();

        match ($definition->type) {
            ContentFieldType::Text, ContentFieldType::LongText => $row->value_text = (string) $value,
            ContentFieldType::Number => $row->value_number = $value,
            ContentFieldType::Date => $row->value_date = $value,
            ContentFieldType::SingleSelection => $row->content_field_option_id = (int) $value,
            ContentFieldType::MultipleSelection => $row->selectedOptions()->sync($this->optionIds($value)),
        };

        $row->save();

        return $row;
    }

    /**
     * Remove the stored row for a definition, if any. Clearing a value is a
     * delete, never a blank row. Any multiple-selection pivot entries go too.
     *
     * @throws ValidationException
     */
    public function clear(ContentItem $item, ContentFieldDefinition $definition): void
    {
        $this->assertDefinitionBelongsToItem($item, $definition);

        $row = ContentFieldValue::query()
            ->where('content_item_id', $item->getKey())
            ->where('content_field_definition_id', $definition->getKey())
            ->first();

        if ($row === null) {
            return;
        }

        $row->selectedOptions()->detach();
        $row->delete();
    }

    /**
     * A definition is only usable by an item of its own project; an unsaved
     * item or a foreign definition is refused.
     *
     * @throws ValidationException
     */
    public function assertDefinitionBelongsToItem(ContentItem $item, ContentFieldDefinition $definition): void
    {
        if (! $item->exists
            || ! $definition->exists
            || (int) $definition->content_project_id !== (int) $item->content_project_id) {
            throw ValidationException::withMessages([
                'content_field_definition_id' => 'El campo no pertenece al proyecto del contenido.',
            ]);
        }
    }

    public function hasStoredValue(ContentItem $item, ContentFieldDefinition $definition): bool
    {
        if (! $item->exists || ! $definition->exists) {
            return false;
        }

        return ContentFieldValue::query()
            ->where('content_item_id', $item->getKey())
            ->where('content_field_definition_id', $definition->getKey())
            ->exists();
    }

    /**
     * All definitions of the item's project, keyed by their stable `key`.
     *
     * @return Collection<string, ContentFieldDefinition>
     */
    private function definitionsFor(ContentItem $item): Collection
    {
        return ContentFieldDefinition::query()
            ->where('content_project_id', $item->content_project_id)
            ->get()
            ->keyBy('key');
    }

    /**
     * @throws ValidationException
     */
    private function assertValidValue(ContentFieldDefinition $definition, mixed $value): void
    {
        $type = $definition->type;
        $key = $definition->key;

        // Base rules declared by the type vocabulary (text, number, date, ...).
        Validator::make(
            [$key => $value],
            [$key => $type->valueRules()],
        )->validate();

        if ($type === ContentFieldType::SingleSelection) {
            $this->assertOptionBelongsToDefinition($definition, (int) $value, $key);

            return;
        }

        if ($type === ContentFieldType::MultipleSelection) {
            Validator::make(
                [$key => $value],
                [$key.'.*' => $type->optionValueRules()],
            )->validate();

            foreach ($this->optionIds($value) as $optionId) {
                $this->assertOptionBelongsToDefinition($definition, $optionId, $key);
            }
        }
    }

    /**
     * Narrow the vocabulary rule: an option is valid only when it belongs to
     * this exact definition, not merely to the options table.
     *
     * @throws ValidationException
     */
    private function assertOptionBelongsToDefinition(ContentFieldDefinition $definition, int $optionId, string $key): void
    {
        $belongs = ContentFieldOption::query()
            ->where('content_field_definition_id', $definition->getKey())
            ->whereKey($optionId)
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                $key => 'La opción no pertenece al campo.',
            ]);
        }
    }

    private function readValue(ContentFieldDefinition $definition, ContentFieldValue $row): mixed
    {
        return match ($definition->type) {
            ContentFieldType::Text, ContentFieldType::LongText => $row->value_text,
            ContentFieldType::Number => $row->value_number,
            ContentFieldType::Date => $row->value_date?->format('Y-m-d'),
            ContentFieldType::SingleSelection => $row->content_field_option_id,
            ContentFieldType::MultipleSelection => $row->selectedOptions->pluck('id')->all(),
        };
    }

    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return count(array_filter($value, fn (mixed $entry): bool => ! $this->isEmpty($entry))) === 0;
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function optionIds(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}

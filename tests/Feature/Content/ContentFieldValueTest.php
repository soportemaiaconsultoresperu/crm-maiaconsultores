<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentFieldType;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFieldOption;
use App\Models\Content\ContentFieldValue;
use App\Models\Content\ContentItem;
use App\Models\Content\ContentProject;
use App\Services\Content\ContentFieldSchemaService;
use App\Services\Content\ContentFieldValueService;
use App\Services\Content\ContentFieldValueUsage;
use App\Services\Content\DatabaseContentFieldValueUsage;
use App\Services\Content\NullContentFieldValueUsage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * T6b — typed custom-field values.
 *
 * Covers the additive value schema, the read/write service that owns every
 * value invariant, and the container binding that turns the T5a type
 * immutability from an inert default into an active rule.
 */
class ContentFieldValueTest extends TestCase
{
    use RefreshDatabase;

    public function test_value_tables_are_additive_and_carry_the_singular_pair(): void
    {
        $this->assertTrue(Schema::hasTable('content_field_values'));

        foreach ([
            'id',
            'content_item_id',
            'content_field_definition_id',
            'value_text',
            'value_number',
            'value_date',
            'content_field_option_id',
            'created_by',
            'updated_by',
            'created_at',
            'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('content_field_values', $column), "Missing content_field_values.{$column}");
        }

        // Values follow their content: no independent soft delete.
        $this->assertFalse(Schema::hasColumn('content_field_values', 'deleted_at'));

        $this->assertTrue(Schema::hasTable('content_field_value_option'));
        foreach (['id', 'content_field_value_id', 'content_field_option_id', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('content_field_value_option', $column), "Missing content_field_value_option.{$column}");
        }
    }

    public function test_a_content_and_definition_own_at_most_one_value_row(): void
    {
        $item = $this->item();
        $field = $this->field($item->project, ContentFieldType::Text, 'tema');

        ContentFieldValue::factory()->create([
            'content_item_id' => $item->id,
            'content_field_definition_id' => $field->id,
            'value_text' => 'primero',
        ]);

        $this->expectException(QueryException::class);
        ContentFieldValue::factory()->create([
            'content_item_id' => $item->id,
            'content_field_definition_id' => $field->id,
            'value_text' => 'segundo',
        ]);
    }

    public function test_each_of_the_six_types_round_trips_its_value(): void
    {
        $project = ContentProject::factory()->create();
        $item = ContentItem::factory()->for($project, 'project')->create();

        $text = $this->field($project, ContentFieldType::Text, 'texto');
        $long = $this->field($project, ContentFieldType::LongText, 'texto_largo');
        $date = $this->field($project, ContentFieldType::Date, 'fecha');
        $number = $this->field($project, ContentFieldType::Number, 'numero');
        $single = $this->field($project, ContentFieldType::SingleSelection, 'unica');
        $multiple = $this->field($project, ContentFieldType::MultipleSelection, 'multiple');

        $singleOption = ContentFieldOption::factory()->for($single, 'definition')->create();
        $multipleOptions = ContentFieldOption::factory()->count(2)->for($multiple, 'definition')->create();
        $longValue = str_repeat('a', 400);

        $this->service()->save($item, [
            $text->key => 'Hola mundo',
            $long->key => $longValue,
            $date->key => '2026-03-15',
            $number->key => '1234.50',
            $single->key => $singleOption->id,
            $multiple->key => $multipleOptions->pluck('id')->all(),
        ]);

        $values = $this->service()->values($item);

        $this->assertSame('Hola mundo', $values[$text->key]);
        $this->assertSame($longValue, $values[$long->key]);
        $this->assertSame('2026-03-15', $values[$date->key]);
        $this->assertSame('1234.50', $values[$number->key]);
        $this->assertSame($singleOption->id, $values[$single->key]);
        $this->assertEqualsCanonicalizing($multipleOptions->pluck('id')->all(), $values[$multiple->key]);

        // A definition can be read with its value for a given content and vice versa.
        $stored = ContentFieldValue::query()
            ->where('content_item_id', $item->id)
            ->where('content_field_definition_id', $number->id)
            ->first();

        $this->assertNotNull($stored);
        $this->assertTrue($stored->item->is($item));
        $this->assertTrue($stored->definition->is($number));
        $this->assertTrue($item->fieldValues()->whereKey($stored->id)->exists());
        $this->assertTrue($number->values()->whereKey($stored->id)->exists());

        $multipleRow = ContentFieldValue::query()
            ->where('content_field_definition_id', $multiple->id)
            ->first();
        $this->assertEqualsCanonicalizing(
            $multipleOptions->pluck('id')->all(),
            $multipleRow->selectedOptions->pluck('id')->all(),
        );
    }

    public function test_a_required_field_without_a_value_is_rejected(): void
    {
        $item = $this->item();
        $required = $this->field($item->project, ContentFieldType::Text, 'obligatorio', ['required' => true]);

        $this->assertRejected($item, [], $required->key);
        $this->assertRejected($item, [$required->key => ''], $required->key);
        $this->assertRejected($item, [$required->key => '   '], $required->key);

        $this->service()->save($item, [$required->key => 'Contenido']);
        $this->assertSame('Contenido', $this->service()->values($item)[$required->key]);

        // Once stored, a later save that omits it still satisfies the rule.
        $other = $this->field($item->project, ContentFieldType::Text, 'otro');
        $this->service()->save($item, [$other->key => 'Algo más']);
        $this->assertSame('Contenido', $this->service()->values($item)[$required->key]);
        $this->assertSame('Algo más', $this->service()->values($item)[$other->key]);
    }

    public function test_an_invalid_number_and_an_invalid_date_are_rejected(): void
    {
        $item = $this->item();
        $number = $this->field($item->project, ContentFieldType::Number, 'numero');
        $date = $this->field($item->project, ContentFieldType::Date, 'fecha');

        $this->assertRejected($item, [$number->key => 'no-es-numero'], $number->key);
        $this->assertRejected($item, [$date->key => 'ayer'], $date->key);
        $this->assertRejected($item, [$date->key => '2026-13-40'], $date->key);

        $this->assertSame(0, ContentFieldValue::query()->count());
    }

    public function test_a_single_selection_value_from_another_definition_is_rejected(): void
    {
        $item = $this->item();
        $first = $this->field($item->project, ContentFieldType::SingleSelection, 'primero');
        $second = $this->field($item->project, ContentFieldType::SingleSelection, 'segundo');

        $own = ContentFieldOption::factory()->for($first, 'definition')->create();
        $foreign = ContentFieldOption::factory()->for($second, 'definition')->create();

        $this->service()->save($item, [$first->key => $own->id]);
        $this->assertSame($own->id, $this->service()->values($item)[$first->key]);

        // The foreign option does exist in the options table, so only the
        // per-definition narrowing can reject it.
        $this->assertRejected($item, [$first->key => $foreign->id], $first->key);
        $this->assertSame($own->id, $this->service()->values($item)[$first->key]);
    }

    public function test_a_multiple_selection_value_with_a_foreign_option_is_rejected(): void
    {
        $item = $this->item();
        $first = $this->field($item->project, ContentFieldType::MultipleSelection, 'primero');
        $second = $this->field($item->project, ContentFieldType::MultipleSelection, 'segundo');

        $own = ContentFieldOption::factory()->for($first, 'definition')->create();
        $foreign = ContentFieldOption::factory()->for($second, 'definition')->create();

        $this->service()->save($item, [$first->key => [$own->id]]);
        $this->assertEqualsCanonicalizing([$own->id], $this->service()->values($item)[$first->key]);

        $this->assertRejected($item, [$first->key => [$own->id, $foreign->id]], $first->key);
        $this->assertEqualsCanonicalizing([$own->id], $this->service()->values($item)[$first->key]);
    }

    public function test_a_definition_from_another_project_is_rejected(): void
    {
        $project = ContentProject::factory()->create();
        $item = ContentItem::factory()->for($project, 'project')->create();
        $foreignField = $this->field(ContentProject::factory()->create(), ContentFieldType::Text, 'campo_ajeno');

        try {
            $this->service()->assertDefinitionBelongsToItem($item, $foreignField);
            $this->fail('A definition from another project must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content_field_definition_id', $exception->errors());
        }

        $this->assertRejected($item, [$foreignField->key => 'x'], $foreignField->key);
        $this->assertSame(0, ContentFieldValue::query()->count());
    }

    public function test_relabelling_and_reordering_definitions_does_not_disturb_stored_values(): void
    {
        $item = $this->item();
        $theme = $this->field($item->project, ContentFieldType::Text, 'tema', ['sort' => 20]);
        $author = $this->field($item->project, ContentFieldType::Text, 'autor', ['sort' => 10]);

        $this->service()->save($item, ['tema' => 'Campaña de marzo', 'autor' => 'Ana']);

        $guard = app(ContentFieldSchemaService::class);
        $guard->updateField($theme, null, ['label' => 'Tema del mes', 'sort' => 30]);
        $guard->updateField($author, null, ['label' => 'Autor del contenido', 'sort' => 0]);

        $values = $this->service()->values($item);

        $this->assertSame('Campaña de marzo', $values['tema']);
        $this->assertSame('Ana', $values['autor']);
        $this->assertSame('tema', $theme->fresh()->key);
        $this->assertSame('autor', $author->fresh()->key);
        $this->assertSame('Tema del mes', $theme->fresh()->label);
        $this->assertSame(1, $theme->values()->count());
        $this->assertSame(1, $author->values()->count());
    }

    public function test_clearing_a_value_removes_its_row_instead_of_storing_a_blank(): void
    {
        $item = $this->item();
        $text = $this->field($item->project, ContentFieldType::Text, 'tema');
        $multiple = $this->field($item->project, ContentFieldType::MultipleSelection, 'canales');
        $options = ContentFieldOption::factory()->count(2)->for($multiple, 'definition')->create();

        $this->service()->save($item, [
            $text->key => 'Algo',
            $multiple->key => $options->pluck('id')->all(),
        ]);

        $this->assertSame(2, ContentFieldValue::query()->count());
        $this->assertSame(2, DB::table('content_field_value_option')->count());

        $this->service()->save($item, [
            $text->key => null,
            $multiple->key => [],
        ]);

        $this->assertSame(0, ContentFieldValue::query()->count());
        $this->assertSame(0, DB::table('content_field_value_option')->count());
        $this->assertArrayNotHasKey($text->key, $this->service()->values($item));
        $this->assertArrayNotHasKey($multiple->key, $this->service()->values($item));
        $this->assertSame(2, ContentFieldOption::query()->count());
    }

    public function test_the_container_binds_the_real_usage_so_type_immutability_is_active(): void
    {
        // If the interface were unbound, this resolution would fail outright and
        // the schema guard below would silently receive NullContentFieldValueUsage,
        // letting the type change through. Both assertions pin that down.
        $this->assertInstanceOf(DatabaseContentFieldValueUsage::class, app(ContentFieldValueUsage::class));
        $this->assertNotInstanceOf(NullContentFieldValueUsage::class, app(ContentFieldValueUsage::class));

        $project = ContentProject::factory()->create();
        $item = ContentItem::factory()->for($project, 'project')->create();
        $used = $this->field($project, ContentFieldType::Text, 'con_valores');
        $unused = $this->field($project, ContentFieldType::Text, 'sin_valores');

        $this->service()->save($item, [$used->key => 'Contenido']);

        $usage = app(ContentFieldValueUsage::class);
        $this->assertTrue($usage->hasStoredValues($used));
        $this->assertFalse($usage->hasStoredValues($unused));

        $guard = app(ContentFieldSchemaService::class);

        try {
            $guard->assertTypeIsMutable($used, ContentFieldType::Number);
            $this->fail('A definition that holds values must refuse a type change.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('type', $exception->errors());
        }
        $this->assertSame(ContentFieldType::Text, $used->fresh()->type);

        // Counterfactual: the old default permits the exact same change, which
        // is why the binding is what makes the rule real.
        (new ContentFieldSchemaService(new NullContentFieldValueUsage))
            ->assertTypeIsMutable($used, ContentFieldType::Number);

        // An unused definition may still change type.
        $guard->updateField($unused, null, ['type' => ContentFieldType::Number]);
        $this->assertSame(ContentFieldType::Number, $unused->fresh()->type);
    }

    private function item(): ContentItem
    {
        return ContentItem::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function field(
        ContentProject $project,
        ContentFieldType $type,
        string $key,
        array $attributes = [],
    ): ContentFieldDefinition {
        return ContentFieldDefinition::factory()
            ->for($project, 'project')
            ->ofType($type)
            ->create(['key' => $key] + $attributes);
    }

    private function service(): ContentFieldValueService
    {
        return app(ContentFieldValueService::class);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function assertRejected(ContentItem $item, array $values, string $field): void
    {
        try {
            $this->service()->save($item, $values);
            $this->fail("A value for {$field} that breaks the contract must be rejected.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}

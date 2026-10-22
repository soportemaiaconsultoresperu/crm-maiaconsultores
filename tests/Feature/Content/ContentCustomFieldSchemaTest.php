<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentFieldType;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFieldOption;
use App\Models\Content\ContentFieldSection;
use App\Models\Content\ContentProject;
use App\Models\User;
use App\Services\Content\ContentFieldSchemaService;
use App\Services\Content\ContentFieldValueUsage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * T5a — project-defined custom fields and sections.
 *
 * Covers the additive schema, the models, the supported-type contract and the
 * schema/immutability guard. Value storage itself belongs to T6, so the
 * type-change check is exercised through its ContentFieldValueUsage seam.
 */
class ContentCustomFieldSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'content_field_sections',
        'content_field_definitions',
        'content_field_options',
    ];

    public function test_custom_field_tables_use_audit_columns_and_deactivation(): void
    {
        foreach (self::TABLES as $table) {
            foreach (['id', 'created_by', 'updated_by', 'created_at', 'updated_at', 'is_active', 'sort'] as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "Missing {$table}.{$column}");
            }
            // Catalogs are deactivated, never soft-deleted.
            $this->assertFalse(Schema::hasColumn($table, 'deleted_at'));
        }

        $this->assertTrue(Schema::hasColumn('content_field_definitions', 'key'));
        $this->assertTrue(Schema::hasColumn('content_field_definitions', 'type'));
        $this->assertTrue(Schema::hasColumn('content_field_definitions', 'required'));
        $this->assertTrue(Schema::hasColumn('content_field_definitions', 'content_field_section_id'));
        $this->assertTrue(Schema::hasColumn('content_field_options', 'label'));
    }

    public function test_sections_fields_and_options_are_created_per_project(): void
    {
        $first = ContentProject::factory()->create();
        $second = ContentProject::factory()->create();

        $firstSection = ContentFieldSection::factory()->for($first, 'project')->create();
        $secondSection = ContentFieldSection::factory()->for($second, 'project')->create();
        $firstField = ContentFieldDefinition::factory()->for($first, 'project')->create([
            'content_field_section_id' => $firstSection->id,
        ]);
        $secondField = ContentFieldDefinition::factory()->for($second, 'project')->create([
            'content_field_section_id' => $secondSection->id,
        ]);
        $option = ContentFieldOption::factory()->for($firstField, 'definition')->create();

        $this->assertTrue($firstSection->project->is($first));
        $this->assertTrue($secondSection->project->is($second));
        $this->assertTrue($firstField->project->is($first));
        $this->assertTrue($firstField->section->is($firstSection));
        $this->assertCount(1, $firstSection->fields);
        $this->assertSame(1, $secondSection->fields()->count());
        $this->assertSame(1, ContentFieldDefinition::where('content_project_id', $first->id)->count());
        $this->assertSame(1, ContentFieldDefinition::where('content_project_id', $second->id)->count());
        $this->assertTrue($option->definition->is($firstField));
    }

    public function test_field_keys_and_slugs_are_unique_within_their_scope_and_reusable_across_projects(): void
    {
        $first = ContentProject::factory()->create();
        $second = ContentProject::factory()->create();

        ContentFieldDefinition::factory()->for($first, 'project')->create(['key' => 'presupuesto']);
        ContentFieldDefinition::factory()->for($second, 'project')->create(['key' => 'presupuesto']);
        $this->assertSame(2, ContentFieldDefinition::count());

        $this->expectException(QueryException::class);
        ContentFieldDefinition::factory()->for($first, 'project')->create(['key' => 'presupuesto']);
    }

    public function test_field_key_is_immutable_after_creation(): void
    {
        $definition = ContentFieldDefinition::factory()->create(['key' => 'tema']);
        $guard = app(ContentFieldSchemaService::class);

        try {
            $guard->assertKeyIsImmutable($definition, 'tema_nuevo');
            $this->fail('Changing a stored key must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('key', $exception->errors());
        }

        $this->assertSame('tema', $definition->fresh()->key);

        // Re-asserting the same key is a no-op, and a brand new field is free.
        $guard->assertKeyIsImmutable($definition, 'tema');
        $guard->assertKeyIsImmutable(new ContentFieldDefinition(['key' => 'borrador']), 'otra_clave');
    }

    public function test_label_can_change_without_touching_the_key(): void
    {
        $definition = ContentFieldDefinition::factory()->create([
            'key' => 'tema',
            'label' => 'Tema',
        ]);
        $guard = app(ContentFieldSchemaService::class);

        $guard->assertKeyIsImmutable($definition, $definition->key);
        $definition->update(['label' => 'Tema principal del mes']);

        $this->assertSame('Tema principal del mes', $definition->fresh()->label);
        $this->assertSame('tema', $definition->fresh()->key);
    }

    public function test_section_must_belong_to_the_addressed_project(): void
    {
        $project = ContentProject::factory()->create();
        $foreign = ContentFieldSection::factory()->create();
        $own = ContentFieldSection::factory()->for($project, 'project')->create();
        $guard = app(ContentFieldSchemaService::class);

        try {
            $guard->assertSectionBelongsToProject($project, $foreign);
            $this->fail('A section from another project must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content_field_section_id', $exception->errors());
        }

        $guard->assertSectionBelongsToProject($project, $own);
        $guard->assertSectionBelongsToProject($project, null);
    }

    public function test_field_cannot_be_assigned_to_another_projects_section(): void
    {
        $project = ContentProject::factory()->create();
        $field = ContentFieldDefinition::factory()->for($project, 'project')->create();
        $foreignSection = ContentFieldSection::factory()->create();
        $ownSection = ContentFieldSection::factory()->for($project, 'project')->create();
        $guard = app(ContentFieldSchemaService::class);

        try {
            $guard->assertSectionAssignment($field, $foreignSection);
            $this->fail('A foreign section must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content_field_section_id', $exception->errors());
        }

        $guard->assertSectionAssignment($field, $ownSection);
        $guard->assertSectionAssignment($field, null);
    }

    public function test_options_are_rejected_for_non_list_types(): void
    {
        $guard = app(ContentFieldSchemaService::class);

        foreach ([ContentFieldType::Text, ContentFieldType::LongText, ContentFieldType::Date, ContentFieldType::Number] as $type) {
            $this->assertFalse($type->acceptsOptions());
            $guard->assertOptionsAllowed($type, false);

            try {
                $guard->assertOptionsAllowed($type, true);
                $this->fail("{$type->value} must not accept options.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('options', $exception->errors());
            }
        }

        foreach ([ContentFieldType::SingleSelection, ContentFieldType::MultipleSelection] as $type) {
            $this->assertTrue($type->acceptsOptions());
            $guard->assertOptionsAllowed($type, true);
        }
    }

    public function test_options_are_unique_per_definition(): void
    {
        $first = ContentFieldDefinition::factory()->listType()->withOptions(2)->create();
        $second = ContentFieldDefinition::factory()->listType()->create();

        $this->assertSame(2, $first->options()->count());

        ContentFieldOption::factory()->for($first, 'definition')->create(['slug' => 'compartido']);
        ContentFieldOption::factory()->for($second, 'definition')->create(['slug' => 'compartido']);

        $this->expectException(QueryException::class);
        ContentFieldOption::factory()->for($first, 'definition')->create(['slug' => 'compartido']);
    }

    public function test_guard_rejects_an_option_from_another_definition(): void
    {
        $first = ContentFieldDefinition::factory()->listType()->create();
        $second = ContentFieldDefinition::factory()->listType()->create();
        $foreignOption = ContentFieldOption::factory()->for($second, 'definition')->create();
        $ownOption = ContentFieldOption::factory()->for($first, 'definition')->create();
        $guard = app(ContentFieldSchemaService::class);

        try {
            $guard->assertOptionBelongsToDefinition($first, $foreignOption);
            $this->fail('An option from another definition must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('content_field_option_id', $exception->errors());
        }

        $guard->assertOptionBelongsToDefinition($first, $ownOption);
    }

    public function test_deactivating_keeps_rows_and_reserves_slugs_while_leaving_the_active_scope(): void
    {
        $project = ContentProject::factory()->create();
        $section = ContentFieldSection::factory()->for($project, 'project')->create(['slug' => 'general']);
        $field = ContentFieldDefinition::factory()->for($project, 'project')->create(['key' => 'tema']);
        $list = ContentFieldDefinition::factory()->for($project, 'project')->listType()->create(['key' => 'canales']);
        $option = ContentFieldOption::factory()->for($list, 'definition')->create(['slug' => 'instagram']);

        $section->deactivate();
        $field->deactivate();
        $list->deactivate();
        $option->deactivate();

        $this->assertFalse($section->fresh()->is_active);
        $this->assertFalse($field->fresh()->is_active);
        $this->assertFalse($option->fresh()->is_active);
        $this->assertNotNull(ContentFieldSection::find($section->id));
        $this->assertNotNull(ContentFieldDefinition::find($field->id));
        $this->assertNotNull(ContentFieldOption::find($option->id));
        $this->assertSame(0, ContentFieldSection::active()->count());
        $this->assertSame(0, ContentFieldDefinition::active()->count());
        $this->assertSame(0, ContentFieldOption::active()->count());
        $this->assertSame(1, ContentFieldSection::count());
        $this->assertSame(2, ContentFieldDefinition::count());
        $this->assertSame(1, ContentFieldOption::count());

        // A deactivated row still reserves its business key.
        $this->assertRejected(ContentFieldSection::class, ['content_project_id' => $project->id, 'slug' => 'general']);
        $this->assertRejected(ContentFieldDefinition::class, ['content_project_id' => $project->id, 'key' => 'tema']);
        $this->assertRejected(ContentFieldOption::class, ['content_field_definition_id' => $list->id, 'slug' => 'instagram']);

        $section->reactivate();
        $field->reactivate();
        $list->reactivate();
        $option->reactivate();
        $this->assertSame(1, ContentFieldSection::active()->count());
        $this->assertSame(2, ContentFieldDefinition::active()->count());
        $this->assertSame(1, ContentFieldOption::active()->count());
    }

    public function test_ordering_is_persisted_and_respected_by_the_active_scope(): void
    {
        $project = ContentProject::factory()->create();
        $later = ContentFieldDefinition::factory()->for($project, 'project')->create(['sort' => 20]);
        $earlier = ContentFieldDefinition::factory()->for($project, 'project')->create(['sort' => 10]);
        $tie = ContentFieldDefinition::factory()->for($project, 'project')->create(['sort' => 10]);
        $retired = ContentFieldDefinition::factory()->for($project, 'project')->create(['sort' => 0]);
        $retired->deactivate();

        $this->assertIsInt($earlier->fresh()->sort);
        $this->assertSame(20, $later->fresh()->sort);
        $this->assertSame(
            [$earlier->id, $tie->id, $later->id],
            ContentFieldDefinition::active()->pluck('id')->all(),
        );
    }

    public function test_creating_a_new_field_requires_no_schema_change(): void
    {
        $project = ContentProject::factory()->create();
        $columnsBefore = Schema::getColumnListing('content_field_definitions');

        // A key/label that exists nowhere in the code or seed must just work.
        $field = ContentFieldDefinition::factory()->for($project, 'project')->create([
            'key' => 'presupuesto_extra_2026',
            'label' => 'Presupuesto adicional 2026',
            'type' => ContentFieldType::Number,
            'required' => true,
        ]);

        $this->assertSame($columnsBefore, Schema::getColumnListing('content_field_definitions'));
        $this->assertFalse(Schema::hasColumn('content_field_definitions', 'presupuesto_extra_2026'));

        $stored = ContentFieldDefinition::findOrFail($field->id);
        $this->assertSame('presupuesto_extra_2026', $stored->key);
        $this->assertSame('Presupuesto adicional 2026', $stored->label);
        $this->assertSame(ContentFieldType::Number, $stored->type);
        $this->assertTrue($stored->required);
    }

    public function test_type_change_is_blocked_only_while_stored_values_depend_on_it(): void
    {
        $definition = ContentFieldDefinition::factory()->create(['type' => ContentFieldType::Text]);
        $usage = new class implements ContentFieldValueUsage
        {
            public bool $hasValues = false;

            public function hasStoredValues(ContentFieldDefinition $definition): bool
            {
                return $this->hasValues;
            }
        };
        $guard = new ContentFieldSchemaService($usage);

        // No stored values: the type is still free to change.
        $guard->assertTypeIsMutable($definition, ContentFieldType::Number);

        $usage->hasValues = true;

        try {
            $guard->assertTypeIsMutable($definition, ContentFieldType::Number);
            $this->fail('A type with stored values must be immutable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('type', $exception->errors());
        }

        // Re-asserting the current type is never blocked.
        $guard->assertTypeIsMutable($definition, ContentFieldType::Text);
        $this->assertSame(ContentFieldType::Text, $definition->fresh()->type);
    }

    public function test_default_value_usage_seam_is_resolved_from_the_container(): void
    {
        // T5a binds nothing: the container falls back to the null implementation
        // so the guard is usable before T6 owns value storage.
        $guard = app(ContentFieldSchemaService::class);
        $definition = ContentFieldDefinition::factory()->create(['type' => ContentFieldType::Text]);

        $this->assertInstanceOf(ContentFieldSchemaService::class, $guard);
        $guard->assertTypeIsMutable($definition, ContentFieldType::Number);
    }

    public function test_supported_type_contract_is_the_single_source_of_truth(): void
    {
        $this->assertSame(
            ['text', 'long_text', 'date', 'number', 'single_selection', 'multiple_selection'],
            ContentFieldType::values(),
        );

        $this->assertSame([
            'text' => 'Texto',
            'long_text' => 'Texto largo',
            'date' => 'Fecha',
            'number' => 'Número',
            'single_selection' => 'Selección única',
            'multiple_selection' => 'Selección múltiple',
        ], collect(ContentFieldType::cases())->mapWithKeys(fn (ContentFieldType $type): array => [$type->value => $type->label()])->all());

        $this->assertContains('string', ContentFieldType::Text->valueRules());
        $this->assertContains('numeric', ContentFieldType::Number->valueRules());
        $this->assertContains('date', ContentFieldType::Date->valueRules());
        $this->assertSame(['array'], ContentFieldType::MultipleSelection->valueRules());
        $this->assertSame(
            ContentFieldType::SingleSelection->optionValueRules(),
            ContentFieldType::SingleSelection->valueRules(),
        );
        $this->assertSame(['integer', 'exists:content_field_options,id'], ContentFieldType::SingleSelection->optionValueRules());
        $this->assertSame([], ContentFieldType::Text->optionValueRules());
    }

    public function test_definition_casts_type_to_the_enum_and_has_an_owned_relation(): void
    {
        $definition = ContentFieldDefinition::factory()->create(['type' => ContentFieldType::MultipleSelection]);
        $option = ContentFieldOption::factory()->for($definition, 'definition')->create();

        $this->assertInstanceOf(ContentFieldType::class, $definition->fresh()->type);
        $this->assertTrue($definition->fresh()->acceptsOptions());
        $this->assertTrue($option->definition->is($definition));
    }

    public function test_composed_factory_state_builds_project_section_and_typed_fields(): void
    {
        $section = ContentFieldSection::factory()->withFields()->create();

        $this->assertSame(1, ContentProject::count());
        $this->assertSame(1, ContentFieldSection::count());
        $this->assertSame(2, ContentFieldDefinition::count());
        $this->assertSame(3, ContentFieldOption::count());

        $section->load('fields.options');
        $types = $section->fields->map(fn (ContentFieldDefinition $field): ContentFieldType => $field->type)->all();
        $this->assertContains(ContentFieldType::Text, $types);
        $this->assertContains(ContentFieldType::SingleSelection, $types);

        foreach ($section->fields as $field) {
            $this->assertTrue($field->project->is($section->project));
        }

        $list = $section->fields->firstWhere('type', ContentFieldType::SingleSelection);
        $this->assertNotNull($list);
        $this->assertCount(3, $list->options);

        $text = $section->fields->firstWhere('type', ContentFieldType::Text);
        $this->assertNotNull($text);
        $this->assertCount(0, $text->options);
    }

    public function test_custom_field_rows_record_the_authenticated_actor(): void
    {
        $actor = User::factory()->create();
        $this->actingAs($actor);

        $section = ContentFieldSection::factory()->create();
        $field = ContentFieldDefinition::factory()->create();
        $option = ContentFieldOption::factory()->create();

        foreach ([$section, $field, $option] as $row) {
            $this->assertSame($actor->id, $row->created_by);
            $row->deactivate();
            $this->assertSame($actor->id, $row->fresh()->updated_by);
        }
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  array<string, mixed>  $attributes
     */
    private function assertRejected(string $model, array $attributes): void
    {
        try {
            (new $model)->newQuery()->create($attributes);
            $this->fail("{$model} must keep its business key reserved.");
        } catch (QueryException $exception) {
            $this->assertSame(1, (new $model)->newQuery()->where($attributes)->count());
        }
    }
}

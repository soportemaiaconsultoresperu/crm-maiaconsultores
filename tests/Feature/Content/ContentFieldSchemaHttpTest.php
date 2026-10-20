<?php

namespace Tests\Feature\Content;

use App\Enums\Content\ContentFieldType;
use App\Models\Content\ContentFieldDefinition;
use App\Models\Content\ContentFieldOption;
use App\Models\Content\ContentFieldSection;
use App\Models\Content\ContentProject;
use App\Models\User;
use Database\Seeders\ContentPermissionsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T5b — administration UI for a project's custom-field sections, definitions
 * and list options.
 *
 * The guard service owns the invariants (key/type immutability, section
 * ownership, options-for-list-types-only); these tests exercise them through
 * the HTTP surface an operator actually uses, including the refusals.
 */
class ContentFieldSchemaHttpTest extends TestCase
{
    use RefreshDatabase;

    private ContentProject $project;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ContentPermissionsSeeder::class);
        $this->project = ContentProject::factory()->create();
        $this->manager = User::factory()->create(['is_active' => true]);
        $this->manager->givePermissionTo(['content_projects.view', 'content_projects.update']);
        $this->project->members()->attach($this->manager);
        $this->actingAs($this->manager);
    }

    public function test_member_with_update_can_reach_the_screen_and_manage_sections_fields_and_options(): void
    {
        // The screen is reachable from the project detail page.
        $this->get(route('content.projects.show', $this->project))->assertOk()
            ->assertSee(route('content.fields.index', $this->project), false);
        $this->get(route('content.fields.index', $this->project))->assertOk()
            ->assertSee('Nueva sección')
            ->assertSee('Todavía no hay secciones')
            ->assertSee('No hay campos sin sección');

        // Section: create, rename, reorder, deactivate, reactivate.
        $this->post(route('content.fields.sections.store', $this->project),
            ['name' => 'Datos generales', 'slug' => 'datos-generales', 'sort' => 10])->assertRedirect();
        $section = ContentFieldSection::query()->where('slug', 'datos-generales')->sole();
        $this->assertSame($this->project->id, $section->content_project_id);

        $this->put(route('content.fields.sections.update', [$this->project, $section->id]),
            ['name' => 'Datos del contenido'])->assertRedirect();
        $this->assertSame('Datos del contenido', $section->fresh()->name);
        $this->assertSame('datos-generales', $section->fresh()->slug);

        $this->post(route('content.fields.sections.reorder', [$this->project, $section->id]), ['sort' => 5])->assertRedirect();
        $this->assertSame(5, $section->fresh()->sort);

        $this->post(route('content.fields.sections.state', [$this->project, $section->id]), ['is_active' => false])->assertRedirect();
        $this->assertFalse($section->fresh()->is_active);
        $this->assertNotNull(ContentFieldSection::find($section->id));
        $this->post(route('content.fields.sections.state', [$this->project, $section->id]), ['is_active' => true])->assertRedirect();
        $this->assertTrue($section->fresh()->is_active);

        // Field: create in a section, rename, change required, move section, reorder, deactivate.
        $this->post(route('content.fields.store', $this->project), [
            'label' => 'Presupuesto adicional 2026',
            'type' => ContentFieldType::Number->value,
            'content_field_section_id' => $section->id,
            'required' => true,
            'help_text' => 'Monto en soles.',
            'sort' => 3,
        ])->assertRedirect();
        $field = ContentFieldDefinition::query()->where('label', 'Presupuesto adicional 2026')->sole();
        $this->assertSame('presupuesto_adicional_2026', $field->key);
        $this->assertSame(ContentFieldType::Number, $field->type);
        $this->assertTrue($field->required);
        $this->assertSame($section->id, $field->content_field_section_id);

        $this->put(route('content.fields.update', [$this->project, $field->id]), [
            'label' => 'Presupuesto del mes',
            'content_field_section_id' => null,
            'required' => false,
        ])->assertRedirect();
        $field->refresh();
        $this->assertSame('Presupuesto del mes', $field->label);
        $this->assertFalse($field->required);
        $this->assertNull($field->content_field_section_id);
        $this->assertSame('presupuesto_adicional_2026', $field->key);

        $this->post(route('content.fields.reorder', [$this->project, $field->id]), ['sort' => 2])->assertRedirect();
        $this->assertSame(2, $field->fresh()->sort);

        $this->post(route('content.fields.state', [$this->project, $field->id]), ['is_active' => false])->assertRedirect();
        $this->assertFalse($field->fresh()->is_active);
        $this->assertNotNull(ContentFieldDefinition::find($field->id));

        // Options: only on a selection field.
        $this->post(route('content.fields.store', $this->project), [
            'label' => 'Canal principal',
            'type' => ContentFieldType::SingleSelection->value,
            'sort' => 4,
        ])->assertRedirect();
        $list = ContentFieldDefinition::query()->where('label', 'Canal principal')->sole();

        $this->post(route('content.fields.options.store', [$this->project, $list->id]),
            ['label' => 'Instagram', 'slug' => 'instagram', 'sort' => 1])->assertRedirect();
        $option = ContentFieldOption::query()->where('slug', 'instagram')->sole();
        $this->assertSame($list->id, $option->content_field_definition_id);

        $this->put(route('content.fields.options.update', [$this->project, $list->id, $option->id]),
            ['label' => 'Instagram oficial'])->assertRedirect();
        $this->assertSame('Instagram oficial', $option->fresh()->label);
        $this->assertSame('instagram', $option->fresh()->slug);

        $this->post(route('content.fields.options.reorder', [$this->project, $list->id, $option->id]), ['sort' => 7])->assertRedirect();
        $this->assertSame(7, $option->fresh()->sort);

        $this->post(route('content.fields.options.state', [$this->project, $list->id, $option->id]), ['is_active' => false])->assertRedirect();
        $this->assertFalse($option->fresh()->is_active);
        $this->get(route('content.fields.index', $this->project))->assertOk()
            ->assertSee('Retirada')
            ->assertSee('Instagram oficial');
    }

    public function test_unreachable_project_is_404_for_the_screen_and_every_action(): void
    {
        $foreign = ContentProject::factory()->create();
        $foreignSection = ContentFieldSection::factory()->create(['content_project_id' => $foreign->id]);
        $foreignField = ContentFieldDefinition::factory()->create(['content_project_id' => $foreign->id]);
        $foreignOption = ContentFieldOption::factory()->for($foreignField, 'definition')->create();

        $this->get(route('content.fields.index', $foreign))->assertNotFound();
        $this->postJson(route('content.fields.sections.store', $foreign), [])->assertNotFound();
        $this->putJson(route('content.fields.sections.update', [$foreign, $foreignSection->id]), [])->assertNotFound();
        $this->postJson(route('content.fields.sections.state', [$foreign, $foreignSection->id]), [])->assertNotFound();
        $this->postJson(route('content.fields.sections.reorder', [$foreign, $foreignSection->id]), [])->assertNotFound();
        $this->postJson(route('content.fields.store', $foreign), [])->assertNotFound();
        $this->putJson(route('content.fields.update', [$foreign, $foreignField->id]), [])->assertNotFound();
        $this->postJson(route('content.fields.state', [$foreign, $foreignField->id]), [])->assertNotFound();
        $this->postJson(route('content.fields.reorder', [$foreign, $foreignField->id]), [])->assertNotFound();
        $this->postJson(route('content.fields.options.store', [$foreign, $foreignField->id]), [])->assertNotFound();
        $this->putJson(route('content.fields.options.update', [$foreign, $foreignField->id, $foreignOption->id]), [])->assertNotFound();
        $this->postJson(route('content.fields.options.state', [$foreign, $foreignField->id, $foreignOption->id]), [])->assertNotFound();
        $this->postJson(route('content.fields.options.reorder', [$foreign, $foreignField->id, $foreignOption->id]), [])->assertNotFound();
    }

    public function test_member_without_update_is_refused_and_mutates_nothing(): void
    {
        $reader = User::factory()->create(['is_active' => true]);
        $reader->givePermissionTo('content_projects.view');
        $this->project->members()->attach($reader);
        $this->actingAs($reader);

        $this->get(route('content.fields.index', $this->project))->assertForbidden();
        $this->get(route('content.projects.show', $this->project))->assertOk()->assertDontSee('Campos personalizados');
        $this->postJson(route('content.fields.sections.store', $this->project), [
            'name' => 'Robada', 'slug' => 'robada', 'sort' => 0,
        ])->assertForbidden();
        $this->postJson(route('content.fields.store', $this->project), [
            'label' => 'Robado', 'type' => ContentFieldType::Text->value, 'sort' => 0,
        ])->assertForbidden();

        $this->assertSame(0, ContentFieldSection::query()->count());
        $this->assertSame(0, ContentFieldDefinition::query()->count());
    }

    public function test_field_key_is_generated_stable_and_cannot_be_changed(): void
    {
        $this->post(route('content.fields.store', $this->project), [
            'label' => 'Presupuesto adicional 2026',
            'type' => ContentFieldType::Text->value,
            'sort' => 0,
        ])->assertRedirect();

        $field = ContentFieldDefinition::query()->sole();
        $this->assertSame('presupuesto_adicional_2026', $field->key);
        $this->get(route('content.fields.index', $this->project))->assertOk()
            ->assertSee('presupuesto_adicional_2026')
            ->assertSee('La clave y el tipo no se pueden cambiar');

        // A later field with the same label cannot steal the reserved key.
        $this->post(route('content.fields.store', $this->project), [
            'label' => 'Presupuesto adicional 2026',
            'type' => ContentFieldType::Text->value,
            'sort' => 1,
        ])->assertRedirect();
        $this->assertSame(2, ContentFieldDefinition::query()->count());
        $this->assertNotSame($field->key, ContentFieldDefinition::query()->whereKeyNot($field->id)->sole()->key);

        // The key is not an editable control and a submitted key is refused.
        $this->putJson(route('content.fields.update', [$this->project, $field->id]), [
            'label' => 'Otro nombre',
            'key' => 'clave_nueva',
        ])->assertUnprocessable()->assertJsonValidationErrors('key');
        $this->assertSame('presupuesto_adicional_2026', $field->fresh()->key);

        // The type is fixed too.
        $this->putJson(route('content.fields.update', [$this->project, $field->id]), [
            'label' => 'Otro nombre',
            'type' => ContentFieldType::Number->value,
        ])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->assertSame(ContentFieldType::Text, $field->fresh()->type);
    }

    public function test_options_are_refused_for_a_non_list_type(): void
    {
        $text = ContentFieldDefinition::factory()->create([
            'content_project_id' => $this->project->id,
            'type' => ContentFieldType::Text,
        ]);

        $this->postJson(route('content.fields.options.store', [$this->project, $text->id]), [
            'label' => 'Opción inválida', 'slug' => 'opcion-invalida', 'sort' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('options');
        $this->assertSame(0, ContentFieldOption::query()->count());

        $this->get(route('content.fields.index', $this->project))->assertOk()
            ->assertSee('no admiten opciones');
    }

    public function test_section_from_another_project_is_refused_when_assigning_a_field(): void
    {
        $foreignSection = ContentFieldSection::factory()->create();

        $this->postJson(route('content.fields.store', $this->project), [
            'label' => 'Campo ajeno',
            'type' => ContentFieldType::Text->value,
            'content_field_section_id' => $foreignSection->id,
            'sort' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('content_field_section_id');
        $this->assertSame(0, ContentFieldDefinition::query()->count());

        $ownSection = ContentFieldSection::factory()->create(['content_project_id' => $this->project->id]);
        $field = ContentFieldDefinition::factory()->create([
            'content_project_id' => $this->project->id,
            'content_field_section_id' => $ownSection->id,
        ]);

        $this->putJson(route('content.fields.update', [$this->project, $field->id]), [
            'label' => $field->label,
            'content_field_section_id' => $foreignSection->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('content_field_section_id');
        $this->assertSame($ownSection->id, $field->fresh()->content_field_section_id);

        // A foreign section addressed directly is refused as well: a 404, the
        // same answer as an unknown id (review fix S22).
        $this->putJson(route('content.fields.sections.update', [$this->project, $foreignSection->id]), [
            'name' => 'Robada',
        ])->assertNotFound();
        $this->assertNotSame('Robada', $foreignSection->fresh()->name);
    }

    public function test_deactivating_keeps_rows_and_reserves_keys_and_slugs(): void
    {
        $section = ContentFieldSection::factory()->create([
            'content_project_id' => $this->project->id,
            'slug' => 'general',
        ]);
        $list = ContentFieldDefinition::factory()->create([
            'content_project_id' => $this->project->id,
            'label' => 'Canal principal',
            'key' => 'canal_principal',
            'type' => ContentFieldType::SingleSelection,
        ]);
        $option = ContentFieldOption::factory()->for($list, 'definition')->create(['slug' => 'instagram']);

        $this->post(route('content.fields.sections.state', [$this->project, $section->id]), ['is_active' => false])->assertRedirect();
        $this->post(route('content.fields.state', [$this->project, $list->id]), ['is_active' => false])->assertRedirect();
        $this->post(route('content.fields.options.state', [$this->project, $list->id, $option->id]), ['is_active' => false])->assertRedirect();

        // The rows survive and are shown as retired.
        $this->assertNotNull(ContentFieldSection::find($section->id));
        $this->assertNotNull(ContentFieldDefinition::find($list->id));
        $this->assertNotNull(ContentFieldOption::find($option->id));
        $this->get(route('content.fields.index', $this->project))->assertOk()
            ->assertSee('Retirada')
            ->assertSee('Retirado');

        // A retired section slug and option slug stay reserved.
        $this->postJson(route('content.fields.sections.store', $this->project),
            ['name' => 'Otra', 'slug' => 'general', 'sort' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->postJson(route('content.fields.options.store', [$this->project, $list->id]),
            ['label' => 'Otra', 'slug' => 'instagram', 'sort' => 0])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');

        // A retired key stays reserved, so a same-label field gets a new key.
        $this->post(route('content.fields.store', $this->project), [
            'label' => 'Canal principal',
            'type' => ContentFieldType::SingleSelection->value,
            'sort' => 0,
        ])->assertRedirect();
        $this->assertSame(2, ContentFieldDefinition::query()->where('label', 'Canal principal')->count());
        $this->assertNotSame('canal_principal',
            ContentFieldDefinition::query()->where('key', '!=', 'canal_principal')->sole()->key);
    }

    public function test_ordering_is_persisted_and_reflected_on_the_screen(): void
    {
        $first = ContentFieldSection::factory()->create([
            'content_project_id' => $this->project->id, 'name' => 'Sección Primera', 'sort' => 1,
        ]);
        $last = ContentFieldSection::factory()->create([
            'content_project_id' => $this->project->id, 'name' => 'Sección Última', 'sort' => 20,
        ]);
        $firstField = ContentFieldDefinition::factory()->create([
            'content_project_id' => $this->project->id,
            'content_field_section_id' => $first->id,
            'label' => 'Campo Primero', 'sort' => 1,
        ]);
        $lastField = ContentFieldDefinition::factory()->create([
            'content_project_id' => $this->project->id,
            'content_field_section_id' => $first->id,
            'label' => 'Campo Último', 'sort' => 20,
        ]);

        $this->post(route('content.fields.sections.reorder', [$this->project, $last->id]), ['sort' => 0])->assertRedirect();
        $this->post(route('content.fields.reorder', [$this->project, $lastField->id]), ['sort' => 0])->assertRedirect();

        $this->assertSame(0, $last->fresh()->sort);
        $this->assertSame(0, $lastField->fresh()->sort);
        $this->get(route('content.fields.index', $this->project))->assertOk()
            ->assertSeeInOrder(['Sección Última', 'Sección Primera'])
            ->assertSeeInOrder(['Campo Último', 'Campo Primero']);
    }
}

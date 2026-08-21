<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Email\Livewire;

use App\Livewire\Admin\Email\TemplateForm;
use App\Models\Email\EmailTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B13 Pasada B — TemplateForm Livewire component tests.
 */
class TemplateFormLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->register(\App\Providers\EmailServiceProvider::class, force: true);
    }

    public function test_create_mode_initialises_with_empty_state(): void
    {
        Livewire::test(TemplateForm::class, ['templateId' => null, 'mode' => 'create'])
            ->assertSet('mode', 'create')
            ->assertSet('templateId', null)
            ->assertSet('name', '')
            ->assertSet('variablesArray', [])
            ->assertSet('isActive', false);
    }

    public function test_add_variable_appends_an_empty_slot(): void
    {
        Livewire::test(TemplateForm::class, ['templateId' => null, 'mode' => 'create'])
            ->assertCount('variablesArray', 0)
            ->call('addVariable')
            ->assertCount('variablesArray', 1)
            ->call('addVariable')
            ->assertCount('variablesArray', 2);
    }

    public function test_remove_variable_splices_the_list_and_updates_preview(): void
    {
        Livewire::test(TemplateForm::class, ['templateId' => null, 'mode' => 'create'])
            ->set('variablesArray', ['customer_name', 'proposal_id'])
            ->call('removeVariable', 0)
            ->assertCount('variablesArray', 1)
            ->assertSet('variablesArray.0', 'proposal_id');
    }

    public function test_edit_mode_loads_an_existing_template(): void
    {
        $template = EmailTemplate::create([
            'name' => 'Cargada',
            'slug' => 'cargada',
            'subject' => 'S',
            'body_html' => '<p>H</p>',
            'body_text' => 'H',
            'variables_json' => ['customer_name'],
            'is_active' => true,
            'version' => 1,
            'created_by' => null,
        ]);

        Livewire::test(TemplateForm::class, ['templateId' => $template->id, 'mode' => 'edit'])
            ->assertSet('mode', 'edit')
            ->assertSet('name', 'Cargada')
            ->assertSet('slug', 'cargada')
            ->assertSet('variablesArray', ['customer_name']);
    }

    public function test_updated_body_html_triggers_preview_refresh(): void
    {
        Livewire::test(TemplateForm::class, ['templateId' => null, 'mode' => 'create'])
            ->set('subject', 'Hola')
            ->set('variablesArray', ['customer_name'])
            ->set('bodyHtml', '<p>Hola {{ customer_name }}</p>')
            ->call('refreshPreview')
            ->assertSet('previewHtml', '<p>Hola «customer_name»</p>');
    }
}

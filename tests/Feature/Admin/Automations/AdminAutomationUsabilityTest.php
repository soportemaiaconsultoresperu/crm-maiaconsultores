<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Automations;

use App\Events\V2\LeadCreated;
use App\Models\AutomationAction;
use App\Models\AutomationRule;
use App\Models\User;
use App\Providers\AutomationServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminAutomationUsabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->register(AutomationServiceProvider::class, force: true);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_manage_user_sees_create_and_rule_management_actions_on_index(): void
    {
        $user = $this->userWithPermissions(['automations.view', 'automations.manage']);
        $rule = $this->rule(['name' => 'Regla visible']);

        $response = $this->actingAs($user)->get(route('admin.automations.index'));

        $response->assertOk();
        $response->assertSee('Nueva automatización');
        $response->assertSee(route('admin.automations.create'), false);
        $response->assertSee('Ver detalle');
        $response->assertSee('Editar');
        $response->assertSee(route('admin.automations.edit', $rule), false);
        $response->assertSee('Duplicar');
        $response->assertSee(route('admin.automations.clone', $rule), false);
        $response->assertSee('Enviar a papelera');
    }

    public function test_view_only_user_does_not_see_manage_actions_on_index(): void
    {
        $user = $this->userWithPermissions(['automations.view']);
        $rule = $this->rule(['name' => 'Solo lectura']);

        $response = $this->actingAs($user)->get(route('admin.automations.index'));

        $response->assertOk();
        $response->assertSee('Ver detalle');
        $response->assertDontSee('Nueva automatización');
        $response->assertDontSee(route('admin.automations.create'), false);
        $response->assertDontSee('Editar');
        $response->assertDontSee(route('admin.automations.edit', $rule), false);
        $response->assertDontSee('Duplicar');
        $response->assertDontSee(route('admin.automations.clone', $rule), false);
        $response->assertDontSee('Enviar a papelera');
    }

    public function test_show_page_exposes_management_actions_and_simulation_when_permitted(): void
    {
        $user = $this->userWithPermissions(['automations.view', 'automations.manage', 'automations.test']);
        $rule = $this->rule(['name' => 'Con acciones']);
        $action = AutomationAction::query()->create([
            'rule_id' => $rule->id,
            'type' => 'add_tag',
            'position' => 1,
            'payload_json' => ['tag_slug' => 'hot-lead'],
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('admin.automations.show', $rule));

        $response->assertOk();
        $response->assertSee('Editar');
        $response->assertSee(route('admin.automations.edit', $rule), false);
        $response->assertSee('Duplicar');
        $response->assertSee(route('admin.automations.clone', $rule), false);
        $response->assertSee('Papelera');
        $response->assertSee(route('admin.automations.destroy', $rule), false);
        $response->assertSee('Acciones configuradas');
        $response->assertSee('Podés probarla sin afectar datos reales');
        $response->assertSee('Simular ahora');
        $response->assertSee('simulate-action-'.$action->id, false);
    }

    public function test_create_form_renders_standard_nested_field_names(): void
    {
        $user = $this->userWithPermissions(['automations.view', 'automations.manage']);

        $response = $this->actingAs($user)->get(route('admin.automations.create'));

        $response->assertOk();
        $response->assertSee('name="name"', false);
        $response->assertSee('name="trigger_event"', false);
        $response->assertSee('name="mode"', false);
        $response->assertSee('name="groups[0][logical_operator]"', false);
        $response->assertSee('name="groups[0][position]"', false);
        $response->assertSee('name="actions[0][type]"', false);
        $response->assertSee('name="actions[0][position]"', false);
        $response->assertSee('name="actions[0][payload_json][tag_slug]"', false);
    }

    public function test_form_shaped_post_persists_rule_condition_and_action_payload(): void
    {
        $user = $this->userWithPermissions(['automations.view', 'automations.manage']);

        $payload = [
            'name' => 'Desde formulario',
            'description' => 'Payload HTML normal',
            'trigger_event' => LeadCreated::class,
            'is_active' => '1',
            'order' => '3',
            'mode' => 'test',
            'owner_id' => null,
            'groups' => [
                0 => [
                    'logical_operator' => 'AND',
                    'position' => '1',
                    'conditions' => [
                        0 => [
                            'field' => 'source_id',
                            'operator' => 'is_not_null',
                            'value' => '',
                            'value_type' => 'string',
                            'position' => '1',
                        ],
                    ],
                ],
            ],
            'actions' => [
                0 => [
                    'type' => 'add_tag',
                    'position' => '1',
                    'channel' => null,
                    'recipient_strategy' => null,
                    'payload_json' => [
                        'tag_slug' => 'from-form',
                        'tag_name' => 'Desde form',
                        'color' => '#ff0000',
                    ],
                    'is_active' => '1',
                ],
            ],
        ];

        $response = $this->actingAs($user)->post(route('admin.automations.store'), $payload);

        $response->assertRedirect();
        $rule = AutomationRule::query()->where('name', 'Desde formulario')->firstOrFail();
        $this->assertSame('source_id', $rule->conditionGroups()->first()->conditions()->first()->field);
        $this->assertSame('add_tag', $rule->actions()->first()->type);
        $this->assertSame('from-form', $rule->actions()->first()->payload_json['tag_slug']);
        $this->assertSame('Desde form', $rule->actions()->first()->payload_json['tag_name']);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function rule(array $overrides = []): AutomationRule
    {
        return AutomationRule::query()->create(array_merge([
            'name' => 'Regla base',
            'trigger_event' => LeadCreated::class,
            'is_active' => true,
            'order' => 1,
            'mode' => 'test',
        ], $overrides));
    }
}

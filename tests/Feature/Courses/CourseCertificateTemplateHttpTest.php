<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\AcademicDocumentType;
use App\Models\Courses\CourseCertificateTemplate;
use App\Models\User;
use App\Services\Courses\CourseCertificateTemplateService;
use Database\Seeders\CoursePermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Slice 6 unit 6.t2 — the certificate template management surface.
 *
 * The controller stays thin: CourseCertificateTemplatePolicy::manage
 * (`course-talks.templates.manage`) authorizes every route and every rendered
 * control, the FormRequests validate shape only, and
 * CourseCertificateTemplateService owns every rule — the `type_scope` allowlist,
 * the settings allowlist and its signature slots, the single-active-template
 * rule per scope, the derived version and the refusal of any attribute the
 * domain does not own. This surface only decides how a domain rejection is
 * reported, so no rejection becomes an HTTP 500.
 *
 * Two attributes must never be configurable from the web: `blade_view` (the
 * render allowlist has exactly one member, so a selector would be theatre) and
 * `html_template` (raw administrator HTML inside a generated PDF is an
 * injection path). Neither has a form field, neither has a rule in the
 * FormRequests, and the domain payload is built key by key — so a tampered
 * payload cannot write either one.
 */
class CourseCertificateTemplateHttpTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(CoursePermissionsSeeder::class);

        $this->manager = $this->userWith([
            'course-talks.view',
            'course-talks.templates.manage',
        ]);
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function service(): CourseCertificateTemplateService
    {
        return app(CourseCertificateTemplateService::class);
    }

    /**
     * A template written the way the domain writes it: the fixtures never
     * hand-roll a row the rules would refuse.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function template(array $overrides = []): CourseCertificateTemplate
    {
        return $this->service()->create(array_merge([
            'name' => 'Plantilla de charlas',
            'type_scope' => AcademicDocumentType::TalkCertificate->value,
        ], $overrides));
    }

    /**
     * The payload the management form submits: exactly the attributes the form
     * exposes, with the settings under the keys the certificate view consumes.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function formPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Plantilla de charlas',
            'type_scope' => AcademicDocumentType::TalkCertificate->value,
            'settings_json' => [
                'title' => 'Constancia oficial',
                'intro_text' => 'Otorga la presente constancia a',
                'company' => 'Maia Academy',
                'signatures' => [
                    ['name' => 'Firma Uno', 'role' => 'Gerencia'],
                    ['name' => 'Firma Dos', 'role' => 'Calidad'],
                ],
            ],
        ], $overrides);
    }

    private function indexUrl(): string
    {
        return route('course-talks.templates.index');
    }

    private function createUrl(): string
    {
        return route('course-talks.templates.create');
    }

    private function editUrl(CourseCertificateTemplate $template): string
    {
        return route('course-talks.templates.edit', $template);
    }

    /**
     * Every template route with its verb, so the denial matrix has one source
     * of truth.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function templateRoutes(CourseCertificateTemplate $template): array
    {
        return [
            'course-talks.templates.index' => ['GET', $this->indexUrl()],
            'course-talks.templates.create' => ['GET', $this->createUrl()],
            'course-talks.templates.store' => ['POST', route('course-talks.templates.store')],
            'course-talks.templates.edit' => ['GET', $this->editUrl($template)],
            'course-talks.templates.update' => ['PUT', route('course-talks.templates.update', $template)],
            'course-talks.templates.activate' => ['POST', route('course-talks.templates.activate', $template)],
            'course-talks.templates.deactivate' => ['POST', route('course-talks.templates.deactivate', $template)],
        ];
    }

    /**
     * A refused payload must show its reason as text on the page it came from,
     * never as a server error and never as a written row.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertRefused(array $payload, string $visibleMessage): void
    {
        // One request chain on purpose: the refusal is flashed for the form the
        // user came from, and following the redirect renders exactly the page
        // that person sees, message included.
        $response = $this->actingAs($this->manager)
            ->from($this->createUrl())
            ->followingRedirects()
            ->post(route('course-talks.templates.store'), $payload);

        $response->assertOk();
        $response->assertSee('data-testid="course-talks-template-errors"', false);
        $response->assertSee($visibleMessage, false);

        $this->assertDatabaseCount('course_certificate_templates', 0);
    }

    /**
     * The declared risk of unit 6.t1, closed at the model: `html_template` is
     * raw administrator HTML that would end up inside a generated PDF, so the
     * model must not mass assign it. This is the assertion that fails before the
     * fix — the column really was reachable through `create()`/`fill()`.
     */
    public function test_the_raw_html_column_cannot_be_mass_assigned(): void
    {
        $created = CourseCertificateTemplate::query()->create([
            'name' => 'Plantilla manipulada',
            'type_scope' => AcademicDocumentType::ApprovalCertificate->value,
            'version' => 1,
            'is_active' => true,
            'blade_view' => CourseCertificateTemplateService::REFERENCE_BLADE_VIEW,
            'html_template' => '<script>alert(1)</script>',
        ]);

        $this->assertNull(
            CourseCertificateTemplate::query()->findOrFail($created->id)->html_template,
            'The raw HTML column must not be reachable through mass assignment.'
        );

        $template = $this->template();

        $template->fill(['html_template' => '<p>inyectado</p>'])->save();

        $this->assertNull(
            CourseCertificateTemplate::query()->findOrFail($template->id)->html_template,
            'fill() must not write the raw HTML column either.'
        );
    }

    public function test_the_list_shows_name_scope_version_active_state_and_settings(): void
    {
        $active = $this->template([
            'name' => 'Plantilla vigente de charlas',
            'settings_json' => [
                'title' => 'Constancia oficial',
                'company' => 'Maia Academy',
                'signatures' => [['name' => 'Firma Uno', 'role' => 'Gerencia']],
            ],
        ]);
        $inactive = $this->template([
            'name' => 'Plantilla apagada de todo tipo',
            'type_scope' => CourseCertificateTemplateService::ANY_TYPE_SCOPE,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->manager)->get($this->indexUrl())->assertOk();

        $response->assertSee('data-testid="course-talks-template-row-'.$active->id.'"', false);
        $response->assertSee('data-testid="course-talks-template-row-'.$inactive->id.'"', false);

        // Name, Spanish scope, derived version and active state.
        $response->assertSee('Plantilla vigente de charlas');
        $response->assertSee('Certificado de charla');
        $response->assertSee('Todos los tipos');
        $response->assertSee('v1');
        $response->assertSee('Activa');
        $response->assertSee('Plantilla apagada de todo tipo');
        $response->assertSee('Inactiva');

        // The configuration the template carries, escaped.
        $response->assertSee('Constancia oficial');
        $response->assertSee('Maia Academy');
        $response->assertSee('Firma Uno');
        $response->assertSee('Gerencia');

        // A template with no configuration says so instead of inventing one.
        $this->template(['name' => 'Plantilla sin configuración', 'is_active' => false]);
        $this->actingAs($this->manager)->get($this->indexUrl())->assertOk()->assertSee('Sin configuración');

        // The render allowlist is not an administrator-facing concept: it is
        // neither selected nor displayed, and no raw column is rendered.
        $response->assertDontSee('blade_view');
        $response->assertDontSee('html_template');
        $response->assertDontSee(CourseCertificateTemplateService::REFERENCE_BLADE_VIEW);
    }

    public function test_an_authorized_user_can_create_a_template_through_the_form(): void
    {
        $response = $this->actingAs($this->manager)
            ->post(route('course-talks.templates.store'), $this->formPayload());

        $response->assertRedirect($this->indexUrl());

        $persisted = CourseCertificateTemplate::query()->sole();

        $this->assertSame('Plantilla de charlas', $persisted->name);
        $this->assertSame(AcademicDocumentType::TalkCertificate->value, $persisted->type_scope);
        $this->assertSame(CourseCertificateTemplateService::REFERENCE_BLADE_VIEW, $persisted->blade_view);
        $this->assertSame(1, $persisted->version);
        $this->assertTrue($persisted->is_active);
        $this->assertNull($persisted->html_template);
        $this->assertSame([
            'title' => 'Constancia oficial',
            'intro_text' => 'Otorga la presente constancia a',
            'company' => 'Maia Academy',
            'signatures' => [
                ['name' => 'Firma Uno', 'role' => 'Gerencia'],
                ['name' => 'Firma Dos', 'role' => 'Calidad'],
            ],
        ], $persisted->settings_json);

        // The created template really is the one generation will resolve.
        $this->assertSame($persisted->id, $this->service()->resolveFor(AcademicDocumentType::TalkCertificate)?->id);
    }

    public function test_the_forms_never_offer_the_raw_html_or_the_blade_view_selector(): void
    {
        $template = $this->template(['settings_json' => ['title' => 'Constancia oficial']]);

        foreach ([$this->createUrl(), $this->editUrl($template)] as $url) {
            $html = (string) $this->actingAs($this->manager)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('name="html_template"', $html, "{$url} must not expose the raw HTML column.");
            $this->assertStringNotContainsString('html_template', $html, "{$url} must not mention the raw HTML column.");
            $this->assertStringNotContainsString('name="blade_view"', $html, "{$url} must not offer a Blade view selector.");
            $this->assertStringNotContainsString('blade_view', $html, "{$url} must not mention the Blade view selector.");
            $this->assertStringNotContainsString(CourseCertificateTemplateService::REFERENCE_BLADE_VIEW, $html, "{$url} must not leak the allowlisted view name.");

            // The settings the domain honours are exactly the ones offered.
            foreach (['title', 'intro_text', 'company'] as $key) {
                $this->assertStringContainsString('name="settings_json['.$key.']"', $html, "{$url} must expose {$key}.");
            }
            $this->assertStringContainsString('name="settings_json[signatures][0][name]"', $html, "{$url} must expose the first signature slot.");
            $this->assertStringContainsString('name="settings_json[signatures][1][role]"', $html, "{$url} must expose the second signature slot.");
            $this->assertStringNotContainsString('name="settings_json[signatures][2]', $html, "{$url} must not offer a third signature slot.");
        }
    }

    public function test_an_authorized_user_can_edit_a_template_and_the_version_is_derived_by_the_domain(): void
    {
        $template = $this->template(['settings_json' => ['title' => 'Constancia oficial']]);

        $form = $this->actingAs($this->manager)->get($this->editUrl($template))->assertOk();
        $form->assertSee('value="Plantilla de charlas"', false);
        $form->assertSee('value="Constancia oficial"', false);

        $response = $this->actingAs($this->manager)
            ->put(route('course-talks.templates.update', $template), $this->formPayload([
                'name' => 'Plantilla renombrada',
                'settings_json' => ['company' => 'Maia Academy'],
            ]));

        $response->assertRedirect($this->indexUrl());

        $persisted = $template->refresh();

        $this->assertSame('Plantilla renombrada', $persisted->name);
        $this->assertSame(['company' => 'Maia Academy'], $persisted->settings_json);
        $this->assertSame(CourseCertificateTemplateService::REFERENCE_BLADE_VIEW, $persisted->blade_view);
        $this->assertSame(2, $persisted->version, 'A configuration change is a new revision.');
    }

    public function test_clearing_every_setting_field_stores_no_configuration_instead_of_empty_text(): void
    {
        $template = $this->template(['settings_json' => ['title' => 'Constancia oficial']]);

        $response = $this->actingAs($this->manager)
            ->from($this->editUrl($template))
            ->put(route('course-talks.templates.update', $template), [
                'name' => 'Plantilla de charlas',
                'type_scope' => AcademicDocumentType::TalkCertificate->value,
                'settings_json' => [
                    'title' => '',
                    'intro_text' => '',
                    'company' => '',
                    'signatures' => [
                        ['name' => '', 'role' => ''],
                        ['name' => '', 'role' => ''],
                    ],
                ],
            ]);

        $response->assertRedirect($this->indexUrl());
        $response->assertSessionHasNoErrors();

        $persisted = $template->refresh();

        $this->assertNull($persisted->settings_json, 'Empty optional fields are "not configured", not an empty override.');
        $this->assertSame(2, $persisted->version);
    }

    public function test_an_authorized_user_can_activate_and_deactivate_a_template(): void
    {
        $active = $this->template(['name' => 'Plantilla vigente']);
        $inactive = $this->template(['name' => 'Plantilla apagada', 'is_active' => false]);

        $this->assertFalse($inactive->is_active);

        $this->actingAs($this->manager)
            ->post(route('course-talks.templates.activate', $inactive))
            ->assertRedirect($this->indexUrl());

        $this->assertTrue($inactive->refresh()->is_active);
        $this->assertSame($inactive->id, $this->service()->resolveFor(AcademicDocumentType::TalkCertificate)?->id);

        $this->actingAs($this->manager)
            ->post(route('course-talks.templates.deactivate', $inactive))
            ->assertRedirect($this->indexUrl());

        $this->assertFalse($inactive->refresh()->is_active);
        $this->assertNull($this->service()->resolveFor(AcademicDocumentType::TalkCertificate));

        // Deactivation is not a configuration revision and never deletes a row.
        $this->assertSame(1, $inactive->refresh()->version);
        $this->assertSame(2, CourseCertificateTemplate::query()->where('type_scope', AcademicDocumentType::TalkCertificate->value)->count());
        $this->assertFalse($active->refresh()->is_active);
    }

    public function test_activating_a_second_template_of_the_same_scope_leaves_exactly_one_active(): void
    {
        $scope = AcademicDocumentType::TalkCertificate->value;
        $first = $this->template(['name' => 'Primera del alcance']);
        $second = $this->template(['name' => 'Segunda del alcance', 'is_active' => false]);
        $otherScope = $this->template([
            'name' => 'Plantilla de otro alcance',
            'type_scope' => AcademicDocumentType::ApprovalCertificate->value,
        ]);

        $this->actingAs($this->manager)
            ->post(route('course-talks.templates.activate', $second))
            ->assertRedirect($this->indexUrl());

        $this->assertTrue($second->refresh()->is_active);
        $this->assertFalse($first->refresh()->is_active, 'Activating a template must switch the previous one for its scope off.');
        $this->assertSame(1, CourseCertificateTemplate::query()->where('type_scope', $scope)->where('is_active', true)->count());
        $this->assertSame($second->id, $this->service()->resolveFor(AcademicDocumentType::TalkCertificate)?->id);
        $this->assertTrue($otherScope->refresh()->is_active, 'Another scope keeps its own active template.');
    }

    public function test_a_user_without_the_templates_permission_sees_no_access_control_and_is_denied_every_route(): void
    {
        $template = $this->template(['name' => 'Plantilla reservada de charlas']);
        $viewer = $this->userWith(['course-talks.view']);

        // The module list is still readable, and it advertises nothing.
        $listHtml = (string) $this->actingAs($viewer)->get(route('course-talks.activities.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString($this->indexUrl(), $listHtml, 'A user without the templates permission must not be offered the management link.');
        $this->assertStringNotContainsString('course-talks-template-list-link', $listHtml);

        foreach ($this->templateRoutes($template) as $routeName => [$method, $url]) {
            $response = $this->actingAs($viewer)->call($method, $url);

            $this->assertSame(403, $response->getStatusCode(), "{$routeName} must be forbidden for a user without course-talks.templates.manage.");
            $response->assertDontSee('Plantilla reservada de charlas');
        }

        // The surfacing page itself keeps no template data for that user.
        $this->actingAs($viewer)->get($this->indexUrl())->assertForbidden()->assertDontSee('Plantilla reservada de charlas');
    }

    public function test_a_user_without_any_permission_is_denied_every_route(): void
    {
        $template = $this->template();
        $stranger = User::factory()->create(['is_active' => true]);

        foreach ($this->templateRoutes($template) as $routeName => [$method, $url]) {
            $this->assertSame(
                403,
                $this->actingAs($stranger)->call($method, $url)->getStatusCode(),
                "{$routeName} must be forbidden"
            );
        }
    }

    public function test_the_surface_is_gated_by_exactly_the_templates_ability(): void
    {
        // Holding only the templates ability is enough: the routes ask for the
        // ability the policy exposes and for nothing else, so no rendered
        // control can answer 403.
        $operator = $this->userWith(['course-talks.templates.manage']);

        $this->actingAs($operator)->get($this->indexUrl())->assertOk();
        $this->actingAs($operator)->get($this->createUrl())->assertOk();
        $this->actingAs($operator)
            ->post(route('course-talks.templates.store'), $this->formPayload(['name' => 'Plantilla del operador']))
            ->assertRedirect($this->indexUrl());

        $this->assertSame(1, CourseCertificateTemplate::query()->where('name', 'Plantilla del operador')->count());
    }

    public function test_the_surface_is_reachable_by_clicking_from_the_activity_list(): void
    {
        $list = $this->actingAs($this->manager)->get(route('course-talks.activities.index'))->assertOk();

        $list->assertSee('data-testid="course-talks-template-list-link"', false);
        $list->assertSee('href="'.$this->indexUrl().'"', false);
        $list->assertSee('Plantillas de certificados');

        // The advertised screen really opens, and it links back (no dead end).
        $templates = $this->actingAs($this->manager)->get($this->indexUrl())->assertOk();
        $templates->assertSee('href="'.route('course-talks.activities.index').'"', false);
        $templates->assertSee('data-testid="course-talks-template-create-link"', false);

        $this->actingAs($this->manager)->get($this->createUrl())->assertOk();
    }

    public function test_an_invalid_type_scope_is_refused_with_a_visible_error_and_writes_no_row(): void
    {
        $this->assertRefused(
            $this->formPayload(['type_scope' => 'aprobado']),
            'Seleccione un alcance válido'
        );

        // The refusal lands on the field the domain named, not only in the
        // form's summary.
        $this->actingAs($this->manager)
            ->from($this->createUrl())
            ->followingRedirects()
            ->post(route('course-talks.templates.store'), $this->formPayload(['type_scope' => 'aprobado']))
            ->assertOk()
            ->assertSee('is-invalid', false);
    }

    public function test_an_unknown_settings_key_is_refused_with_a_visible_error_and_writes_no_row(): void
    {
        $payload = $this->formPayload();
        $payload['settings_json']['background_color'] = '#ffffff';

        $this->assertRefused($payload, 'claves que el certificado no usa');
    }

    public function test_more_than_two_signatures_are_refused_with_a_visible_error_and_writes_no_row(): void
    {
        $payload = $this->formPayload([
            'settings_json' => [
                'title' => 'Constancia oficial',
                'signatures' => [
                    ['name' => 'Firma Uno', 'role' => 'Gerencia'],
                    ['name' => 'Firma Dos', 'role' => 'Calidad'],
                    ['name' => 'Firma Tres', 'role' => 'Académica'],
                ],
            ],
        ]);

        $this->assertRefused($payload, 'sólo admite dos firmas');
    }

    public function test_a_payload_carrying_html_template_or_blade_view_cannot_write_either(): void
    {
        $payload = $this->formPayload([
            'blade_view' => 'admin.users.index',
            'html_template' => '<html><script>alert(1)</script></html>',
            'version' => 99,
        ]);

        $this->actingAs($this->manager)
            ->post(route('course-talks.templates.store'), $payload)
            ->assertRedirect($this->indexUrl());

        $persisted = CourseCertificateTemplate::query()->sole();

        $this->assertSame(CourseCertificateTemplateService::REFERENCE_BLADE_VIEW, $persisted->blade_view);
        $this->assertNull($persisted->html_template, 'A tampered payload must never write the raw HTML column.');
        $this->assertSame(1, $persisted->version, 'The version is derived, never supplied.');

        // Same boundary on the update verb: the stored row keeps both untouched.
        $response = $this->actingAs($this->manager)
            ->put(route('course-talks.templates.update', $persisted), $this->formPayload([
                'name' => 'Plantilla renombrada',
                'blade_view' => 'admin.users.index',
                'html_template' => '<html><script>alert(2)</script></html>',
            ]));

        $response->assertRedirect($this->indexUrl());

        $updated = $persisted->refresh();

        $this->assertSame('Plantilla renombrada', $updated->name);
        $this->assertSame(CourseCertificateTemplateService::REFERENCE_BLADE_VIEW, $updated->blade_view);
        $this->assertNull($updated->html_template);
    }

    public function test_a_domain_refusal_on_update_leaves_the_stored_template_untouched(): void
    {
        $template = $this->template(['settings_json' => ['title' => 'Constancia oficial']]);
        $before = CourseCertificateTemplate::query()->findOrFail($template->id)->toArray();

        $response = $this->actingAs($this->manager)
            ->from($this->editUrl($template))
            ->followingRedirects()
            ->put(route('course-talks.templates.update', $template), $this->formPayload([
                'name' => 'Nombre que no se guardará',
                'type_scope' => 'aprobado',
            ]));

        $response->assertOk();
        $response->assertSee('Seleccione un alcance válido', false);

        $this->assertSame($before, CourseCertificateTemplate::query()->findOrFail($template->id)->toArray());
    }

    public function test_a_malformed_settings_payload_is_refused_as_a_shape_error_not_a_server_error(): void
    {
        $response = $this->actingAs($this->manager)
            ->from($this->createUrl())
            ->followingRedirects()
            ->post(route('course-talks.templates.store'), [
                'name' => 'Plantilla de charlas',
                'type_scope' => AcademicDocumentType::TalkCertificate->value,
                'settings_json' => 'title=Constancia',
            ]);

        $response->assertOk();
        $response->assertSee('El campo configuración debe ser un arreglo.', false);
        $this->assertDatabaseCount('course_certificate_templates', 0);
    }
}

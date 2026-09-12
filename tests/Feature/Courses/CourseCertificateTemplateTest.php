<?php

namespace Tests\Feature\Courses;

use App\Enums\Courses\AcademicDocumentType;
use App\Exceptions\Courses\InvalidCourseCertificateTemplate;
use App\Models\Courses\CourseCertificateTemplate;
use App\Services\Courses\CourseCertificateTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class CourseCertificateTemplateTest extends TestCase
{
    use RefreshDatabase;
    public function test_reference_template_renders_required_certificate_and_temario_pages(): void
    {
        $viewModel = (new CourseCertificateTemplateService())->makeViewModel([
            'title' => 'Certificado de aprobación',
            'participant' => 'Alvaro Segundo Alama Silva',
            'activity' => 'Curso Avanzado de Saneamiento Ambiental',
            'modality' => 'Híbrida',
            'date_range' => '01-04.07.26',
            'academic_hours' => '24',
            'issue_location_date' => 'Lima, 05 de julio de 2026',
            'certificate_code' => 'CERT-2026-0001',
            'qr_svg' => '<svg role="img"><text>QR</text></svg>',
            'signatures' => [
                ['name' => 'Dra. Andrea Maia', 'role' => 'Dirección Académica'],
                ['name' => 'Ing. Luis Rojas', 'role' => 'Coordinación'],
            ],
            'syllabus' => [
                ['class' => 'Clase 1', 'topic' => 'Marco normativo', 'speaker' => 'Dra. Andrea Maia', 'date' => '01.07.26'],
                ['class' => 'Clase 2', 'topic' => 'Fiscalización sanitaria', 'speaker' => 'Ing. Luis Rojas', 'date' => '02.07.26'],
            ],
        ]);

        $html = View::make('course-talks.certificates.reference', ['certificate' => $viewModel])->render();

        $this->assertStringContainsString('Certificado de aprobación', $html);
        $this->assertStringContainsString('Alvaro Segundo Alama Silva', $html);
        $this->assertStringContainsString('Curso Avanzado de Saneamiento Ambiental', $html);
        $this->assertStringContainsString('Híbrida', $html);
        $this->assertStringContainsString('01-04.07.26', $html);
        $this->assertStringContainsString('24 horas académicas', $html);
        $this->assertStringContainsString('Lima, 05 de julio de 2026', $html);
        $this->assertStringContainsString('CERT-2026-0001', $html);
        $this->assertStringContainsString('Dra. Andrea Maia', $html);
        $this->assertStringContainsString('Ing. Luis Rojas', $html);
        $this->assertStringContainsString('Temario', $html);
        $this->assertStringContainsString('Marco normativo', $html);
        $this->assertStringContainsString('Fiscalización sanitaria', $html);
        $this->assertStringContainsString('page-break-after: always', $html);
        $this->assertStringNotContainsString('<script', strtolower($html));
    }

    public function test_admin_settings_customize_text_and_signatures_without_removing_required_data(): void
    {
        $viewModel = (new CourseCertificateTemplateService())->makeViewModel([
            'participant' => 'Rosa Pérez',
            'activity' => 'Charla de Bioseguridad',
            'modality' => 'Virtual',
            'date_range' => '10.08.26',
            'academic_hours' => '2.5',
            'issue_location_date' => 'Arequipa, 11 de agosto de 2026',
            'certificate_code' => 'CHARLA-77',
            'qr_svg' => '<svg><text>QR2</text></svg>',
            'syllabus' => [['class' => 'Única', 'topic' => 'Buenas prácticas', 'speaker' => 'Equipo Maia', 'date' => '10.08.26']],
        ], [
            'title' => 'Constancia oficial',
            'intro_text' => 'Otorga la presente constancia a',
            'company' => 'Maia Academy',
            'signatures' => [
                ['name' => 'Firma Uno', 'role' => 'Gerencia'],
                ['name' => 'Firma Dos', 'role' => 'Calidad'],
            ],
        ]);

        $html = View::make('course-talks.certificates.reference', ['certificate' => $viewModel])->render();

        $this->assertStringContainsString('Constancia oficial', $html);
        $this->assertStringContainsString('Otorga la presente constancia a', $html);
        $this->assertStringContainsString('Rosa Pérez', $html);
        $this->assertStringContainsString('Charla de Bioseguridad', $html);
        $this->assertStringContainsString('Virtual', $html);
        $this->assertStringContainsString('2.5 horas académicas', $html);
        $this->assertStringContainsString('Firma Uno', $html);
        $this->assertStringContainsString('Firma Dos', $html);
        $this->assertStringContainsString('Maia Academy', $html);
        $this->assertStringContainsString('Buenas prácticas', $html);
    }

    /**
     * The whole configuration surface the certificate view consumes, stored
     * unchanged: only the keys `makeViewModel()` honours, in their normalized
     * shape, and never a raw HTML body.
     */
    public function test_a_template_created_through_the_domain_carries_the_reference_view_and_its_configuration(): void
    {
        $template = $this->created([
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
        ]);

        $persisted = CourseCertificateTemplate::query()->findOrFail($template->id);
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

        $this->assertSame($template->id, $this->service()->resolveFor(AcademicDocumentType::TalkCertificate)?->id);
        $this->assertNull($this->service()->resolveFor(AcademicDocumentType::ParticipationConstancy));
    }

    public function test_a_template_without_configuration_stores_no_settings_and_falls_back_to_the_view_defaults(): void
    {
        $template = $this->created(['name' => 'Sin configuración', 'type_scope' => '*']);

        $this->assertNull(CourseCertificateTemplate::query()->findOrFail($template->id)->settings_json);

        $viewModel = $this->service()->makeViewModel([
            'participant' => 'Rosa Pérez',
            'activity' => 'Charla de Bioseguridad',
            'modality' => 'Virtual',
            'date_range' => '10.08.26',
            'academic_hours' => '2.5',
            'issue_location_date' => 'Arequipa, 11 de agosto de 2026',
            'certificate_code' => 'CHARLA-77',
            'qr_svg' => '<svg><text>QR</text></svg>',
            'syllabus' => [],
        ], CourseCertificateTemplate::query()->findOrFail($template->id)->settings_json ?? []);

        $this->assertSame('Certificado', $viewModel->title);
        $this->assertSame('Otorga el presente certificado a', $viewModel->introText);
        $this->assertSame('Maia Consultores', $viewModel->company);
        $this->assertCount(2, $viewModel->signatures);
    }

    public function test_an_unusable_name_is_refused_and_writes_no_row(): void
    {
        foreach ([
            'missing' => ['type_scope' => '*'],
            'empty' => ['name' => '', 'type_scope' => '*'],
            'blank' => ['name' => '   ', 'type_scope' => '*'],
            'not a string' => ['name' => 42, 'type_scope' => '*'],
        ] as $case => $payload) {
            $refusal = $this->refusalOf(fn () => $this->service()->create($payload));

            $this->assertSame(InvalidCourseCertificateTemplate::NAME_REQUIRED, $refusal->reason(), $case);
            $this->assertSame('name', $refusal->field(), $case);
        }

        $this->assertDatabaseCount('course_certificate_templates', 0);
    }

    public function test_a_type_scope_outside_the_allowlist_is_refused_and_writes_no_row(): void
    {
        foreach (['aprobado', 'any', 'APPROVAL_CERTIFICATE', '', 'certificado', 'approval_certificate '] as $scope) {
            $refusal = $this->refusalOf(fn () => $this->service()->create(['name' => 'Plantilla', 'type_scope' => $scope]));

            $this->assertSame(InvalidCourseCertificateTemplate::INVALID_TYPE_SCOPE, $refusal->reason(), $scope);
        }

        // A missing scope is not silently a wildcard: only the explicit one is.
        $missing = $this->refusalOf(fn () => $this->service()->create(['name' => 'Plantilla']));
        $this->assertSame(InvalidCourseCertificateTemplate::INVALID_TYPE_SCOPE, $missing->reason());
        $this->assertDatabaseCount('course_certificate_templates', 0);

        // Control: the three document types plus the explicit wildcard are exactly the allowlist.
        $this->assertSame('*', CourseCertificateTemplateService::ANY_TYPE_SCOPE);
        foreach ([...array_column(AcademicDocumentType::cases(), 'value'), '*'] as $scope) {
            $this->created(['name' => 'Plantilla '.$scope, 'type_scope' => $scope]);
        }

        $this->assertDatabaseCount('course_certificate_templates', 4);
    }

    public function test_a_blade_view_outside_the_allowlist_is_refused_and_writes_no_row(): void
    {
        foreach (['course-talks.certificates.alternative', 'admin.users.index', '../../etc/passwd', '', 'course-talks.certificates.reference '] as $view) {
            $refusal = $this->refusalOf(fn () => $this->service()->create([
                'name' => 'Plantilla',
                'type_scope' => '*',
                'blade_view' => $view,
            ]));

            $this->assertSame(InvalidCourseCertificateTemplate::INVALID_BLADE_VIEW, $refusal->reason(), $view);
            $this->assertSame('blade_view', $refusal->field(), $view);
        }

        $this->assertDatabaseCount('course_certificate_templates', 0);
        $this->assertSame([CourseCertificateTemplateService::REFERENCE_BLADE_VIEW], CourseCertificateTemplateService::BLADE_VIEWS);
    }

    public function test_a_malformed_or_unknown_keyed_settings_payload_is_refused_and_writes_no_row(): void
    {
        $cases = [
            'unknown key' => [['title' => 'X', 'html' => '<b>inyectado</b>'], 'unknown_setting_keys'],
            'design key no view honours' => [['background_color' => '#ffffff'], 'unknown_setting_keys'],
            'raw html body' => [['html_template' => '<p>inyectado</p>'], 'unknown_setting_keys'],
            'not a map' => ['title=Constancia', 'invalid_settings'],
            'a list' => [['Constancia'], 'invalid_settings'],
            'blank title' => [['title' => '  '], 'invalid_settings'],
            'non string company' => [['company' => ['Maia']], 'invalid_settings'],
            'signatures not a list' => [['signatures' => 'Firma Uno'], 'invalid_settings'],
            'no signatures' => [['signatures' => []], 'invalid_settings'],
            'extra signature key' => [['signatures' => [['name' => 'A', 'role' => 'B', 'html' => '<b>']]], 'invalid_settings'],
            'signature without role' => [['signatures' => [['name' => 'A']]], 'invalid_settings'],
            'blank signature name' => [['signatures' => [['name' => '', 'role' => 'B']]], 'invalid_settings'],
        ];

        foreach ($cases as $case => [$settings, $expectedReason]) {
            $refusal = $this->refusalOf(fn () => $this->service()->create([
                'name' => 'Plantilla',
                'type_scope' => '*',
                'settings_json' => $settings,
            ]));

            $this->assertSame($expectedReason, $refusal->reason(), $case);
        }

        $this->assertDatabaseCount('course_certificate_templates', 0);

        // The published tags, pinned as the strings a presentation layer branches on.
        $this->assertSame('unknown_setting_keys', InvalidCourseCertificateTemplate::UNKNOWN_SETTING_KEYS);
        $this->assertSame('invalid_settings', InvalidCourseCertificateTemplate::INVALID_SETTINGS);
    }

    public function test_more_than_two_signatures_are_refused_and_write_no_row(): void
    {
        $refusal = $this->refusalOf(fn () => $this->service()->create([
            'name' => 'Plantilla',
            'type_scope' => '*',
            'settings_json' => ['signatures' => [
                ['name' => 'Uno', 'role' => 'Académica'],
                ['name' => 'Dos', 'role' => 'Calidad'],
                ['name' => 'Tres', 'role' => 'Gerencia'],
            ]],
        ]));

        $this->assertSame(InvalidCourseCertificateTemplate::TOO_MANY_SIGNATURES, $refusal->reason());
        $this->assertSame('signatures', $refusal->field());
        $this->assertDatabaseCount('course_certificate_templates', 0);
    }

    public function test_html_template_is_refused_on_create_and_on_update_and_never_reaches_the_column(): void
    {
        $refusal = $this->refusalOf(fn () => $this->service()->create([
            'name' => 'Plantilla',
            'type_scope' => '*',
            'html_template' => '<html><script>alert(1)</script></html>',
        ]));

        $this->assertSame(InvalidCourseCertificateTemplate::UNKNOWN_ATTRIBUTE, $refusal->reason());
        $this->assertSame('html_template', $refusal->field());
        $this->assertDatabaseCount('course_certificate_templates', 0);

        $template = $this->created(['name' => 'Plantilla real', 'type_scope' => '*']);

        $refusal = $this->refusalOf(fn () => $this->service()->update($template, ['html_template' => '<html>inyectado</html>']));

        $this->assertSame(InvalidCourseCertificateTemplate::UNKNOWN_ATTRIBUTE, $refusal->reason());
        $this->assertNull(CourseCertificateTemplate::query()->findOrFail($template->id)->html_template);
    }

    public function test_the_version_is_derived_by_the_domain_instead_of_supplied(): void
    {
        $refusal = $this->refusalOf(fn () => $this->service()->create([
            'name' => 'Plantilla',
            'type_scope' => '*',
            'version' => 7,
        ]));
        $this->assertSame(InvalidCourseCertificateTemplate::UNKNOWN_ATTRIBUTE, $refusal->reason());
        $this->assertSame('version', $refusal->field());

        $template = $this->created(['name' => 'Plantilla', 'type_scope' => '*']);
        $this->assertSame(1, $template->version);

        $renamed = $this->service()->update($template, ['name' => 'Plantilla renombrada']);
        $this->assertSame(2, $renamed->version);

        $unchanged = $this->service()->update($renamed, ['name' => 'Plantilla renombrada']);
        $this->assertSame(2, $unchanged->version, 'A no-op update must not manufacture a revision.');

        $deactivated = $this->service()->update($unchanged, ['is_active' => false]);
        $this->assertFalse($deactivated->is_active);
        $this->assertSame(2, $deactivated->version, 'Activation is not a configuration revision.');

        $reconfigured = $this->service()->update($deactivated, ['settings_json' => ['title' => 'Constancia oficial', 'company' => 'Maia Academy']]);
        $this->assertSame(3, $reconfigured->version);

        $reordered = $this->service()->update($reconfigured, ['settings_json' => ['company' => 'Maia Academy', 'title' => 'Constancia oficial']]);
        $this->assertSame(3, $reordered->version, 'The same configuration in another key order is not a new revision.');
    }

    public function test_a_refused_update_leaves_the_stored_template_untouched(): void
    {
        $template = $this->created([
            'name' => 'Vigente',
            'type_scope' => '*',
            'settings_json' => ['title' => 'Constancia oficial'],
        ]);
        $before = CourseCertificateTemplate::query()->findOrFail($template->id)->toArray();

        foreach ([
            ['blade_view' => 'course-talks.certificates.alternative'],
            ['settings_json' => ['title' => 'Constancia', 'background_color' => '#fff']],
            ['settings_json' => ['title' => '']],
            ['settings_json' => ['signatures' => [
                ['name' => 'Uno', 'role' => 'A'],
                ['name' => 'Dos', 'role' => 'B'],
                ['name' => 'Tres', 'role' => 'C'],
            ]]],
            ['type_scope' => 'aprobado'],
            ['name' => ''],
        ] as $payload) {
            $this->refusalOf(fn () => $this->service()->update($template, $payload));
        }

        $this->assertSame($before, CourseCertificateTemplate::query()->findOrFail($template->id)->toArray());
    }

    public function test_activating_a_template_deactivates_the_previous_one_for_the_same_scope(): void
    {
        $scope = AcademicDocumentType::TalkCertificate->value;
        $first = $this->created(['name' => 'Primera', 'type_scope' => $scope]);
        $second = $this->created(['name' => 'Segunda', 'type_scope' => $scope]);

        $this->assertTrue($second->is_active);
        $this->assertFalse(CourseCertificateTemplate::query()->findOrFail($first->id)->is_active);
        $this->assertSame(1, CourseCertificateTemplate::query()->where('type_scope', $scope)->where('is_active', true)->count());
        $this->assertSame($second->id, $this->service()->resolveFor(AcademicDocumentType::TalkCertificate)?->id);

        $reactivated = $this->service()->activate($first);

        $this->assertTrue($reactivated->is_active);
        $this->assertFalse(CourseCertificateTemplate::query()->findOrFail($second->id)->is_active);
        $this->assertSame($first->id, $this->service()->resolveFor(AcademicDocumentType::TalkCertificate)?->id);

        $this->service()->deactivate($first);

        $this->assertNull($this->service()->resolveFor(AcademicDocumentType::TalkCertificate));
    }

    public function test_a_type_specific_template_beats_the_wildcard_and_the_wildcard_survives(): void
    {
        $wildcard = $this->created(['name' => 'Cualquier tipo', 'type_scope' => '*']);

        $this->assertSame($wildcard->id, $this->service()->resolveFor(AcademicDocumentType::ApprovalCertificate)?->id);
        $this->assertSame($wildcard->id, $this->service()->resolveFor(AcademicDocumentType::TalkCertificate)?->id);

        $specific = $this->created(['name' => 'Sólo charlas', 'type_scope' => AcademicDocumentType::TalkCertificate->value]);

        $this->assertSame($specific->id, $this->service()->resolveFor(AcademicDocumentType::TalkCertificate)?->id);
        $this->assertSame($wildcard->id, $this->service()->resolveFor(AcademicDocumentType::ApprovalCertificate)?->id);
        $this->assertTrue(
            CourseCertificateTemplate::query()->findOrFail($wildcard->id)->is_active,
            'A specific template must not switch the wildcard off for every other document type.'
        );

        $replacement = $this->created(['name' => 'Cualquier tipo v2', 'type_scope' => '*']);

        $this->assertFalse(CourseCertificateTemplate::query()->findOrFail($wildcard->id)->is_active);
        $this->assertTrue(CourseCertificateTemplate::query()->findOrFail($specific->id)->is_active);
        $this->assertSame($replacement->id, $this->service()->resolveFor(AcademicDocumentType::ApprovalCertificate)?->id);
        $this->assertSame($specific->id, $this->service()->resolveFor(AcademicDocumentType::TalkCertificate)?->id);

        foreach (['*', AcademicDocumentType::TalkCertificate->value] as $scope) {
            $this->assertSame(1, CourseCertificateTemplate::query()->where('type_scope', $scope)->where('is_active', true)->count());
        }
    }

    public function test_an_inactive_template_never_resolves(): void
    {
        $template = $this->created([
            'name' => 'Apagada',
            'type_scope' => '*',
            'is_active' => false,
        ]);

        $this->assertFalse($template->is_active);
        $this->assertNull($this->service()->resolveFor(AcademicDocumentType::ApprovalCertificate));
        $this->assertNull($this->service()->resolveFor(AcademicDocumentType::ParticipationConstancy));
        $this->assertNull($this->service()->resolveFor(AcademicDocumentType::TalkCertificate));
        $this->assertSame(1, CourseCertificateTemplate::query()->whereKey($template->id)->count());
    }

    public function test_a_partial_configuration_keeps_only_the_keys_it_sets(): void
    {
        $template = $this->created([
            'name' => 'Sólo empresa',
            'type_scope' => '*',
            'settings_json' => ['company' => 'Maia Academy'],
        ]);

        $this->assertSame(
            ['company' => 'Maia Academy'],
            CourseCertificateTemplate::query()->findOrFail($template->id)->settings_json,
            'A partial configuration must not invent the keys it does not set.'
        );
    }

    public function test_activating_through_an_update_takes_the_scope_from_the_active_template(): void
    {
        $approval = $this->created(['name' => 'Aprobación', 'type_scope' => AcademicDocumentType::ApprovalCertificate->value]);
        $talk = $this->created(['name' => 'Charlas', 'type_scope' => AcademicDocumentType::TalkCertificate->value]);

        // Moving an active template onto another scope takes that scope over.
        $moved = $this->service()->update($talk, ['type_scope' => AcademicDocumentType::ApprovalCertificate->value]);

        $this->assertSame(AcademicDocumentType::ApprovalCertificate->value, $moved->type_scope);
        $this->assertTrue($moved->is_active);
        $this->assertFalse(CourseCertificateTemplate::query()->findOrFail($approval->id)->is_active);
        $this->assertNull($this->service()->resolveFor(AcademicDocumentType::TalkCertificate));

        // Switching a deactivated template back on through an update does the same.
        $reactivated = $this->service()->update($approval, ['is_active' => true]);

        $this->assertTrue($reactivated->is_active);
        $this->assertFalse(CourseCertificateTemplate::query()->findOrFail($moved->id)->is_active);
        $this->assertSame($approval->id, $this->service()->resolveFor(AcademicDocumentType::ApprovalCertificate)?->id);
        $this->assertSame(1, CourseCertificateTemplate::query()->where('type_scope', AcademicDocumentType::ApprovalCertificate->value)->where('is_active', true)->count());
    }

    public function test_resolution_is_deterministic_and_fails_closed_on_rows_the_domain_never_wrote(): void
    {
        $columns = ['version' => 1, 'is_active' => true, 'blade_view' => 'course-talks.certificates.reference'];
        $first = CourseCertificateTemplate::query()->create(['name' => 'Primera', 'type_scope' => 'approval_certificate'] + $columns);
        $second = CourseCertificateTemplate::query()->create(['name' => 'Segunda', 'type_scope' => 'approval_certificate'] + $columns);

        $this->assertTrue($first->id < $second->id);
        $this->assertSame(
            $first->id,
            $this->service()->resolveFor(AcademicDocumentType::ApprovalCertificate)?->id,
            'With two actives on one scope the oldest revision wins, so resolution never alternates.'
        );

        // A scope the domain never writes (a hand-written or legacy row) is inert
        // instead of resolving for every document type.
        CourseCertificateTemplate::query()->create(['name' => 'Sin alcance', 'type_scope' => null, 'settings_json' => ['title' => 'No debe usarse']] + $columns);

        $this->assertSame($first->id, $this->service()->resolveFor(AcademicDocumentType::ApprovalCertificate)?->id);
        $this->assertNull($this->service()->resolveFor(AcademicDocumentType::TalkCertificate));
    }

    private function service(): CourseCertificateTemplateService
    {
        return new CourseCertificateTemplateService();
    }

    /**
     * Creates a template the rules accept, failing as an assertion when the
     * domain refuses a valid payload or cannot accept it yet.
     */
    private function created(array $payload): CourseCertificateTemplate
    {
        try {
            return $this->service()->create($payload);
        } catch (\Throwable $exception) {
            $this->fail('The domain refused a valid certificate template: '.$exception->getMessage());
        }
    }

    /**
     * Runs a payload the rules forbid and returns the tagged refusal. The class
     * is pinned as a string literal on purpose: while the configuration method
     * does not exist the throwable is `Error: Call to undefined method …`, and
     * that must fail as an assertion about the rule's outcome — an `Error` is
     * not a statement of what the rule promises.
     */
    private function refusalOf(callable $attempt): InvalidCourseCertificateTemplate
    {
        try {
            $attempt();
        } catch (\Throwable $exception) {
            $this->assertSame('App\Exceptions\Courses\InvalidCourseCertificateTemplate', $exception::class, $exception->getMessage());

            return $exception;
        }

        $this->fail('The domain accepted a payload the certificate template rules forbid.');
    }
}

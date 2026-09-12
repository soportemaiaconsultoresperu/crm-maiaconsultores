<?php

namespace App\Http\Controllers\CourseTalks;

use App\Enums\Courses\AcademicDocumentType;
use App\Exceptions\Courses\InvalidCourseCertificateTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\CourseTalks\StoreCourseCertificateTemplateRequest;
use App\Http\Requests\CourseTalks\UpdateCourseCertificateTemplateRequest;
use App\Models\Courses\CourseCertificateTemplate;
use App\Services\Courses\CourseCertificateTemplateService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Authenticated certificate template management: the list of the configured
 * templates, their creation and edition forms, and the activate/deactivate
 * actions that decide which stored revision is in force.
 *
 * Thin by design. CourseCertificateTemplatePolicy::manage
 * (`course-talks.templates.manage`) is the only ability this surface asks for —
 * the same one the FormRequests ask for, so no rendered control can answer 403 —
 * and CourseCertificateTemplateService owns every rule: the `type_scope`
 * allowlist and its explicit `*` wildcard, the `settings_json` keys a
 * certificate view consumes and its two signature slots, the derived version,
 * the once-active-template-per-scope exclusivity, and the refusal of any
 * attribute the domain does not own. Nothing here reimplements any of them; a
 * refusal becomes a Spanish message next to the field it names, never a 500.
 *
 * Two things this surface deliberately does NOT expose: `blade_view` (the
 * render allowlist has exactly one member, so a selector would be theatre — and
 * choosing the view that renders a legal document is not an administrator's
 * decision) and `html_template` (raw administrator HTML inside a generated PDF
 * is an injection path). Neither is rendered, neither has a validation rule, and
 * both are structurally absent from the payload the FormRequest builds.
 */
class CourseCertificateTemplateController extends Controller
{
    public function __construct(
        private readonly CourseCertificateTemplateService $templates,
    ) {}

    public function index(): View
    {
        Gate::authorize('manage', CourseCertificateTemplate::class);

        return view('course-talks.templates.index', [
            'templates' => CourseCertificateTemplate::query()
                ->orderBy('type_scope')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'typeScopes' => $this->typeScopes(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('manage', CourseCertificateTemplate::class);

        return view('course-talks.templates.create', [
            'typeScopes' => $this->typeScopes(),
        ]);
    }

    public function store(StoreCourseCertificateTemplateRequest $request): RedirectResponse
    {
        Gate::authorize('manage', CourseCertificateTemplate::class);

        try {
            $template = $this->templates->create($request->payload());
        } catch (InvalidCourseCertificateTemplate $refusal) {
            return $this->backToFormWithRefusal($refusal, route('course-talks.templates.create'));
        }

        return redirect()
            ->route('course-talks.templates.index')
            ->with('status', 'Plantilla "'.$template->name.'" creada correctamente.');
    }

    public function edit(CourseCertificateTemplate $certificateTemplate): View
    {
        Gate::authorize('manage', CourseCertificateTemplate::class);

        return view('course-talks.templates.edit', [
            'template' => $certificateTemplate,
            'typeScopes' => $this->typeScopes(),
        ]);
    }

    public function update(
        UpdateCourseCertificateTemplateRequest $request,
        CourseCertificateTemplate $certificateTemplate,
    ): RedirectResponse {
        Gate::authorize('manage', CourseCertificateTemplate::class);

        try {
            $template = $this->templates->update($certificateTemplate, $request->payload());
        } catch (InvalidCourseCertificateTemplate $refusal) {
            return $this->backToFormWithRefusal($refusal, route('course-talks.templates.edit', $certificateTemplate));
        }

        return redirect()
            ->route('course-talks.templates.index')
            ->with('status', 'Plantilla "'.$template->name.'" actualizada en la versión '.$template->version.'.');
    }

    /**
     * The template generation will resolve for its scope. The domain sweeps the
     * other templates of that scope, which is why this action carries no rule of
     * its own.
     */
    public function activate(CourseCertificateTemplate $certificateTemplate): RedirectResponse
    {
        Gate::authorize('manage', CourseCertificateTemplate::class);

        $template = $this->templates->activate($certificateTemplate);

        return redirect()
            ->route('course-talks.templates.index')
            ->with('status', 'Plantilla "'.$template->name.'" activada. Es la que se usará en su alcance hasta que active otra.');
    }

    /** Takes the template out of force without deleting any revision of it. */
    public function deactivate(CourseCertificateTemplate $certificateTemplate): RedirectResponse
    {
        Gate::authorize('manage', CourseCertificateTemplate::class);

        $template = $this->templates->deactivate($certificateTemplate);

        return redirect()
            ->route('course-talks.templates.index')
            ->with('status', 'Plantilla "'.$template->name.'" desactivada. No se usará hasta que se active una plantilla de su alcance.');
    }

    /**
     * The domain refusal, worded for the person filling the form and reported
     * next to the field the domain named, so a rejection is always visible and
     * never a server error. The wording branches on the refusal's stable reason
     * tag, never on its message text.
     */
    private function backToFormWithRefusal(InvalidCourseCertificateTemplate $refusal, string $url): RedirectResponse
    {
        return redirect()
            ->to($url)
            ->withInput()
            ->withErrors([$this->refusalErrorKey($refusal) => $this->refusalMessage($refusal)]);
    }

    /**
     * The form field a refusal belongs to. The domain names the attribute it
     * refused, and this surface only translates that name into the key its own
     * form uses, so the message appears next to the input to fix; an attribute
     * the form does not carry (the ones it deliberately never exposes) keeps
     * its refusal visible in the form's summary instead.
     */
    private function refusalErrorKey(InvalidCourseCertificateTemplate $refusal): string
    {
        return match ($refusal->field()) {
            'name' => 'name',
            'type_scope' => 'type_scope',
            'settings_json' => 'settings_json',
            'signatures' => 'settings_json.signatures',
            default => 'template',
        };
    }

    private function refusalMessage(InvalidCourseCertificateTemplate $refusal): string
    {
        return match ($refusal->reason()) {
            InvalidCourseCertificateTemplate::NAME_REQUIRED => 'Indique un nombre para la plantilla.',
            InvalidCourseCertificateTemplate::INVALID_TYPE_SCOPE => 'Seleccione un alcance válido: un tipo de documento o «todos los tipos».',
            InvalidCourseCertificateTemplate::INVALID_BLADE_VIEW => 'La vista de certificado no está permitida por el sistema.',
            InvalidCourseCertificateTemplate::UNKNOWN_SETTING_KEYS => 'La configuración incluye claves que el certificado no usa. Sólo se admiten título, texto de introducción, empresa y firmas.',
            InvalidCourseCertificateTemplate::TOO_MANY_SIGNATURES => 'El certificado sólo admite dos firmas. Elimine las firmas adicionales.',
            InvalidCourseCertificateTemplate::INVALID_SETTINGS => 'Revise la configuración: cada firma necesita un nombre y un cargo, y los textos no pueden quedar vacíos.',
            default => 'El sistema no admite uno de los datos enviados. Revise el formulario e intente nuevamente.',
        };
    }

    /**
     * The Spanish labels of the scopes the form offers and the list renders.
     * The three document types come from the enum and the wildcard from the
     * domain's own constant, so this map adds wording and never a rule.
     *
     * @return array<string, string>
     */
    private function typeScopes(): array
    {
        $labels = [
            AcademicDocumentType::ApprovalCertificate->value => 'Certificado de aprobación',
            AcademicDocumentType::ParticipationConstancy->value => 'Constancia de participación',
            AcademicDocumentType::TalkCertificate->value => 'Certificado de charla',
        ];

        $scopes = [];

        foreach (AcademicDocumentType::cases() as $type) {
            $scopes[$type->value] = $labels[$type->value];
        }

        $scopes[CourseCertificateTemplateService::ANY_TYPE_SCOPE] = 'Todos los tipos';

        return $scopes;
    }
}

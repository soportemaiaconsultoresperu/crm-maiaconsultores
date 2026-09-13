<?php

namespace App\Services\Courses;

use App\Enums\Courses\AcademicDocumentType;
use App\Exceptions\Courses\InvalidCourseCertificateTemplate;
use App\Models\Courses\CourseCertificateTemplate;
use App\ViewModels\Courses\CourseCertificateViewModel;
use Illuminate\Support\Facades\DB;

/**
 * The certificate template domain: the configuration an administrator may
 * change and the rule that decides which template a generated certificate
 * uses.
 *
 * Two of these rules are security rules rather than form rules. `blade_view` is
 * restricted to the frozen render allowlist, so no administrator can choose
 * which Blade view the system renders, and `html_template` — raw HTML that
 * would end up inside a generated PDF — is not an attribute this domain owns,
 * so no path here reads or writes it. The rest of the rules live in the same
 * place: the domain decides, and a future controller only transports.
 *
 * Authorization is deliberately not taken here: `CourseCertificateTemplatePolicy`
 * (`course-talks.templates.manage`) is the surface contract, exactly as
 * `CourseActivityService::create()` leaves it to `CourseActivityPolicy`, and no
 * route or controller can reach these methods in this unit.
 */
class CourseCertificateTemplateService
{
    /**
     * The only Blade view a certificate may render. An administrator
     * configures text and signatures, never the code that produces the PDF.
     */
    public const REFERENCE_BLADE_VIEW = 'course-talks.certificates.reference';

    /** The render allowlist; the reference view is the only member. */
    public const BLADE_VIEWS = [self::REFERENCE_BLADE_VIEW];

    /**
     * The `type_scope` wildcard: the template applies to every document type
     * that has no template of its own.
     *
     * It is an explicit value instead of `null` on purpose. Once a row is read
     * back, a `null` scope is indistinguishable from "this row was never
     * configured", so a literal wildcard is the only representation that still
     * means what it says in the database; a `null` or unknown scope therefore
     * resolves to nothing at all.
     */
    public const ANY_TYPE_SCOPE = '*';

    /**
     * The `settings_json` keys `makeViewModel()` honours. A key outside this
     * set configures nothing, so it is refused instead of stored.
     */
    private const SETTING_KEYS = ['title', 'intro_text', 'company', 'signatures'];

    /** The settings whose value is a non-empty string. */
    private const TEXT_SETTINGS = ['title', 'intro_text', 'company'];

    /** The signature slots the certificate view renders (`array_slice(..., 0, 2)`). */
    private const SIGNATURE_SLOTS = 2;

    /** The attributes this domain owns; anything else in a payload is refused. */
    private const OWNED_ATTRIBUTES = ['name', 'type_scope', 'blade_view', 'is_active', 'settings_json'];

    /** The attributes a change to which produces a new revision. */
    private const VERSIONED_ATTRIBUTES = ['name', 'type_scope', 'blade_view', 'settings_json'];

    public function makeViewModel(array $businessData, array $settings = []): CourseCertificateViewModel
    {
        $signatures = $businessData['signatures'] ?? $settings['signatures'] ?? $this->defaultSignatures();

        return new CourseCertificateViewModel(
            title: (string) ($settings['title'] ?? $businessData['title'] ?? 'Certificado'),
            introText: (string) ($settings['intro_text'] ?? 'Otorga el presente certificado a'),
            participant: (string) $businessData['participant'],
            activity: (string) $businessData['activity'],
            modality: (string) $businessData['modality'],
            dateRange: (string) $businessData['date_range'],
            academicHours: (string) $businessData['academic_hours'],
            issueLocationDate: (string) $businessData['issue_location_date'],
            certificateCode: (string) $businessData['certificate_code'],
            qrSvg: (string) $businessData['qr_svg'],
            signatures: array_slice(array_values($signatures), 0, 2),
            syllabus: array_values($businessData['syllabus'] ?? []),
            company: (string) ($settings['company'] ?? 'Maia Consultores'),
        );
    }

    /**
     * Create a template from validated attributes only.
     *
     * The payload never reaches the model as a whole: the columns this domain
     * does not own stay untouched, and a payload carrying one — `html_template`,
     * a caller-supplied `version` — is refused before any write happens.
     */
    public function create(array $attributes): CourseCertificateTemplate
    {
        $payload = $this->validatedAttributes($attributes, creating: true);

        return DB::transaction(function () use ($payload): CourseCertificateTemplate {
            $template = CourseCertificateTemplate::query()->create($payload + ['version' => 1]);

            $this->enforceSingleActiveTemplatePerScope($template);

            return $template->refresh();
        });
    }

    /**
     * Apply a partial change to a stored template. Absent attributes are left
     * alone; the version is derived, not supplied: it counts the revisions of a
     * template, so a change that alters the configuration (in any key order)
     * increments it and a no-op change does not.
     */
    public function update(CourseCertificateTemplate $template, array $attributes): CourseCertificateTemplate
    {
        $payload = $this->validatedAttributes($attributes, creating: false);

        return DB::transaction(function () use ($template, $payload): CourseCertificateTemplate {
            $current = CourseCertificateTemplate::query()->lockForUpdate()->findOrFail($template->id);

            $changes = [];
            foreach (self::OWNED_ATTRIBUTES as $attribute) {
                if (array_key_exists($attribute, $payload) && $payload[$attribute] !== $current->{$attribute}) {
                    $changes[$attribute] = $payload[$attribute];
                }
            }

            if ($changes === []) {
                return $current;
            }

            if (array_intersect_key($changes, array_flip(self::VERSIONED_ATTRIBUTES)) !== []) {
                $changes['version'] = ((int) $current->version) + 1;
            }

            $current->forceFill($changes)->save();

            $this->enforceSingleActiveTemplatePerScope($current->refresh());

            return $current->refresh();
        });
    }

    /**
     * Make a template the one its scope resolves to.
     *
     * Activation is not a configuration revision, so it does not touch the
     * version: it only decides which stored revision is in force.
     */
    public function activate(CourseCertificateTemplate $template): CourseCertificateTemplate
    {
        return DB::transaction(function () use ($template): CourseCertificateTemplate {
            $current = CourseCertificateTemplate::query()->lockForUpdate()->findOrFail($template->id);

            if (! $current->is_active) {
                $current->forceFill(['is_active' => true])->save();
            }

            $this->enforceSingleActiveTemplatePerScope($current);

            return $current->refresh();
        });
    }

    /** Take a template out of force without deleting any revision of it. */
    public function deactivate(CourseCertificateTemplate $template): CourseCertificateTemplate
    {
        return DB::transaction(function () use ($template): CourseCertificateTemplate {
            $current = CourseCertificateTemplate::query()->lockForUpdate()->findOrFail($template->id);

            if ($current->is_active) {
                $current->forceFill(['is_active' => false])->save();
            }

            return $current->refresh();
        });
    }

    /**
     * The template a generation of this document type must use, or null when
     * none is configured. Read-only: resolution never mutates a template.
     *
     * A template scoped to the exact type wins over the any-type wildcard, and
     * activating a specific template leaves the wildcard in force for the types
     * that have none of their own — deactivating it would silently strip the
     * configuration from every other document type. `blade_view` is filtered
     * against the allowlist here too, so a row written outside this domain (a
     * direct database write, a restore, a future surface) can never steer the
     * render: it resolves to nothing and generation falls back to the reference
     * view instead of failing the certificate.
     */
    public function resolveFor(AcademicDocumentType $type): ?CourseCertificateTemplate
    {
        $candidates = CourseCertificateTemplate::query()
            ->where('is_active', true)
            ->whereIn('type_scope', [$type->value, self::ANY_TYPE_SCOPE])
            ->whereIn('blade_view', self::BLADE_VIEWS)
            ->orderBy('id')
            ->get();

        return $candidates->firstWhere('type_scope', $type->value)
            ?? $candidates->firstWhere('type_scope', self::ANY_TYPE_SCOPE);
    }

    /**
     * At most one active template per `type_scope`: activating one deactivates
     * the others that would resolve for the same scope, so resolution is
     * deterministic instead of "whichever row was activated last".
     */
    private function enforceSingleActiveTemplatePerScope(CourseCertificateTemplate $template): void
    {
        if (! $template->is_active) {
            return;
        }

        CourseCertificateTemplate::query()
            ->whereKeyNot($template->id)
            ->where('type_scope', $template->type_scope)
            ->where('is_active', true)
            ->lockForUpdate()
            ->update(['is_active' => false]);
    }

    /**
     * @return array<string, mixed> only the attributes this domain owns, and only
     *                                the ones the caller supplied once `creating` is false
     */
    private function validatedAttributes(array $attributes, bool $creating): array
    {
        foreach (array_keys($attributes) as $attribute) {
            if (! in_array($attribute, self::OWNED_ATTRIBUTES, true)) {
                throw InvalidCourseCertificateTemplate::unknownAttribute((string) $attribute);
            }
        }

        $payload = [];

        if ($creating || array_key_exists('name', $attributes)) {
            $name = $attributes['name'] ?? null;
            $payload['name'] = is_string($name) ? trim($name) : '';

            if ($payload['name'] === '') {
                throw InvalidCourseCertificateTemplate::nameRequired();
            }
        }

        if ($creating || array_key_exists('type_scope', $attributes)) {
            $payload['type_scope'] = $this->validatedTypeScope($attributes['type_scope'] ?? null);
        }

        if ($creating || array_key_exists('blade_view', $attributes)) {
            $payload['blade_view'] = $this->validatedBladeView($attributes['blade_view'] ?? self::REFERENCE_BLADE_VIEW);
        }

        if ($creating || array_key_exists('is_active', $attributes)) {
            // The same coercion the model's `is_active => boolean` cast applies.
            $payload['is_active'] = (bool) ($attributes['is_active'] ?? true);
        }

        if ($creating || array_key_exists('settings_json', $attributes)) {
            $payload['settings_json'] = $this->validatedSettings($attributes['settings_json'] ?? null);
        }

        return $payload;
    }

    private function validatedTypeScope(mixed $scope): string
    {
        if (! is_string($scope) || ($scope !== self::ANY_TYPE_SCOPE && AcademicDocumentType::tryFrom($scope) === null)) {
            throw InvalidCourseCertificateTemplate::invalidTypeScope($scope);
        }

        return $scope;
    }

    private function validatedBladeView(mixed $view): string
    {
        if (! is_string($view) || ! in_array($view, self::BLADE_VIEWS, true)) {
            throw InvalidCourseCertificateTemplate::invalidBladeView($view);
        }

        return $view;
    }

    /**
     * @return array<string, mixed>|null null is the single representation of
     *                                "this template configures nothing"
     */
    private function validatedSettings(mixed $settings): ?array
    {
        if ($settings === null || $settings === []) {
            return null;
        }

        if (! is_array($settings) || array_is_list($settings)) {
            throw InvalidCourseCertificateTemplate::invalidSettings('settings_json', 'a map of the supported keys');
        }

        $unknown = array_values(array_diff(array_keys($settings), self::SETTING_KEYS));
        if ($unknown !== []) {
            throw InvalidCourseCertificateTemplate::unknownSettingKeys($unknown);
        }

        // Rebuilt in a fixed order, so two payloads with the same configuration
        // in a different key order compare equal and do not fake a revision.
        $normalized = [];
        foreach (self::TEXT_SETTINGS as $key) {
            if (! array_key_exists($key, $settings)) {
                continue;
            }

            $value = $settings[$key];
            if (! is_string($value) || trim($value) === '') {
                throw InvalidCourseCertificateTemplate::invalidSettings($key, 'a non-empty string');
            }

            $normalized[$key] = trim($value);
        }

        if (array_key_exists('signatures', $settings)) {
            $normalized['signatures'] = $this->validatedSignatures($settings['signatures']);
        }

        return $normalized === [] ? null : $normalized;
    }

    /** @return list<array{name: string, role: string}> */
    private function validatedSignatures(mixed $signatures): array
    {
        $expected = 'a list of one or two signature maps with a name and a role';

        if (! is_array($signatures) || ! array_is_list($signatures) || $signatures === []) {
            throw InvalidCourseCertificateTemplate::invalidSettings('signatures', $expected);
        }

        if (count($signatures) > self::SIGNATURE_SLOTS) {
            // makeViewModel() slices the extras away, so accepting them would
            // store a signature that can never render.
            throw InvalidCourseCertificateTemplate::tooManySignatures(count($signatures));
        }

        $normalized = [];
        foreach ($signatures as $signature) {
            $keys = is_array($signature) ? array_keys($signature) : [];
            sort($keys);

            if ($keys !== ['name', 'role']) {
                throw InvalidCourseCertificateTemplate::invalidSettings('signatures', $expected);
            }

            $name = $signature['name'];
            $role = $signature['role'];

            if (! is_string($name) || trim($name) === '' || ! is_string($role) || trim($role) === '') {
                throw InvalidCourseCertificateTemplate::invalidSettings('signatures', 'a name and a role, both non-empty strings');
            }

            $normalized[] = ['name' => trim($name), 'role' => trim($role)];
        }

        return $normalized;
    }

    private function defaultSignatures(): array
    {
        return [
            ['name' => 'Dirección Académica', 'role' => 'Maia Consultores'],
            ['name' => 'Coordinación Académica', 'role' => 'Maia Consultores'],
        ];
    }
}

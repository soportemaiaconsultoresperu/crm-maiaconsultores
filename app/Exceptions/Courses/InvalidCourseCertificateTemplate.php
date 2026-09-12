<?php

namespace App\Exceptions\Courses;

/**
 * A certificate-template payload the domain refused, tagged with the reason
 * that caused it.
 *
 * The reason travels as a stable tag — the same shape
 * {@see InvalidCourseDocumentState::reason()} publishes — so a presentation
 * layer can word the refusal for the case it knows without parsing the message
 * text, and `field()` names the attribute to fix, like
 * {@see InvalidCourseEditionData::field()}. It extends
 * \InvalidArgumentException, so every boundary that already caught one keeps
 * working unchanged.
 *
 * These refusals exist because a certificate template decides what a legal
 * document renders. An unknown setting key, an off-allowlist Blade view or an
 * administrator-supplied HTML body has to be refused where it is written,
 * never silently ignored: a silently ignored key is a configuration an
 * administrator believes took effect when it did not.
 */
class InvalidCourseCertificateTemplate extends \InvalidArgumentException
{
    /** The template needs a usable name to be identifiable. */
    public const NAME_REQUIRED = 'name_required';

    /** The payload carries an attribute the domain does not own, e.g. `html_template`. */
    public const UNKNOWN_ATTRIBUTE = 'unknown_attribute';

    /** `type_scope` is neither a document type nor the explicit any-type wildcard. */
    public const INVALID_TYPE_SCOPE = 'invalid_type_scope';

    /** `blade_view` is not on the render allowlist. */
    public const INVALID_BLADE_VIEW = 'invalid_blade_view';

    /** A value has a shape the certificate view model cannot consume. */
    public const INVALID_SETTINGS = 'invalid_settings';

    /** `settings_json` carries keys no certificate view consumes. */
    public const UNKNOWN_SETTING_KEYS = 'unknown_setting_keys';

    /** More signatures than the certificate view renders. */
    public const TOO_MANY_SIGNATURES = 'too_many_signatures';

    private function __construct(string $message, private readonly string $reason, private readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public static function nameRequired(): self
    {
        return new self('A certificate template needs a name.', self::NAME_REQUIRED, 'name');
    }

    public static function unknownAttribute(string $attribute): self
    {
        return new self(
            sprintf('The certificate template domain does not own the attribute [%s].', $attribute),
            self::UNKNOWN_ATTRIBUTE,
            $attribute,
        );
    }

    public static function invalidTypeScope(mixed $scope): self
    {
        return new self(
            sprintf('The type scope [%s] is not a document type or the any-type wildcard.', self::describe($scope)),
            self::INVALID_TYPE_SCOPE,
            'type_scope',
        );
    }

    public static function invalidBladeView(mixed $view): self
    {
        return new self(
            sprintf('The Blade view [%s] is not on the certificate render allowlist.', self::describe($view)),
            self::INVALID_BLADE_VIEW,
            'blade_view',
        );
    }

    public static function invalidSettings(string $field, string $expected): self
    {
        return new self(
            sprintf('The certificate setting [%s] must be %s.', $field, $expected),
            self::INVALID_SETTINGS,
            $field,
        );
    }

    public static function unknownSettingKeys(array $keys): self
    {
        return new self(
            sprintf('The certificate settings carry keys no certificate view consumes: [%s].', implode(', ', $keys)),
            self::UNKNOWN_SETTING_KEYS,
            'settings_json',
        );
    }

    public static function tooManySignatures(int $count): self
    {
        return new self(
            sprintf('A certificate renders at most two signatures; %d were given.', $count),
            self::TOO_MANY_SIGNATURES,
            'signatures',
        );
    }

    /** The stable reason, safe for presentation layers to branch on. */
    public function reason(): string
    {
        return $this->reason;
    }

    /** The payload attribute that must be fixed, or the settings key at fault. */
    public function field(): ?string
    {
        return $this->field;
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}

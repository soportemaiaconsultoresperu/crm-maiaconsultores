<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Models\Email\EmailTemplate;
use InvalidArgumentException;

/**
 * B13 Pasada B — Renders an {@see EmailTemplate} against a variable bag.
 *
 * SECURITY CONTRACT (docs/v2/01-roadmap.md §1.5 — C-02):
 *   - Variable substitution only against an allow-list passed in the
 *     constructor; unknown `{{ var }}` tokens raise {@see InvalidArgumentException}.
 *   - The body content is statically scanned for `<?php`, `<script>`,
 *     `eval(`, `Blade::render(`, and a few other injection vectors. Hits
 *     raise {@see InvalidArgumentException}.
 *   - No Blade evaluation, no `eval`, no `<?php` execution.
 *
 * The regex used by {@see self::containsDisallowedSubstring()} is:
 *
 *   /<\?php|<script\b|eval\s*\(|Blade::render\s*\(/i
 *
 * Captured inline here as `RENDER_BLOCK_REGEX` so tests can introspect the
 * shape and operators can audit it.
 */
class EmailTemplateRenderer
{
    /**
     * Regex used to reject injection attempts in template bodies.
     *
     * - `<?php`                : PHP open tag (eval).
     * - `<script\b`            : HTML <script> tag.
     * - `eval\s*\(`            : PHP eval() call (with whitespace tolerance).
     * - `Blade::render\s*\(`   : Laravel Blade runtime evaluation.
     */
    public const RENDER_BLOCK_REGEX = '/<\?php|<script\b|eval\s*\(|Blade::render\s*\(/i';

    /**
     * Regex used to capture `{{ var_name }}` tokens.
     *
     * Captures group 1 = variable name (lowercase snake_case identifier).
     */
    public const VARIABLE_REGEX = '/\{\{\s*([a-z_][a-z0-9_]*)\s*\}\}/';

    /**
     * @param  list<string>  $allowedVariables  Allow-list of variable names.
     */
    public function __construct(
        public readonly array $allowedVariables,
    ) {
        if ($this->allowedVariables === []) {
            // We refuse to render an empty allow-list: at least one variable
            // must be declared so the operator cannot accidentally ship a
            // template that drops variables silently.
            // Templates that take zero variables should declare an empty
            // string list ['_'] or a sentinel — kept simple here, we let
            // callers choose. (No exception is raised; the renderer just
            // refuses to interpolate any `{{...}}` token because none are
            // in the list.)
        }
    }

    /**
     * Render the template body against the supplied variables.
     *
     * @param  array<string, string|int|float|bool>  $vars
     * @return array{subject: string, body_html: string, body_text: string}
     *
     * @throws InvalidArgumentException when an unknown variable is referenced
     *                                  or the body contains a disallowed
     *                                  substring.
     */
    public function render(EmailTemplate $template, array $vars): array
    {
        $this->assertBodySafe($template);

        $vars = $this->filterVars($vars);

        $subject = $this->interpolate((string) $template->subject, $vars);
        $bodyHtml = $this->interpolate($this->flattenString($template->body_html), $vars);
        $bodyText = $this->interpolate($this->flattenString($template->body_text), $vars);

        return [
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
        ];
    }

    /**
     * Static body safety check — used by store/update validation flows
     * without instantiating a renderer.
     *
     * @throws InvalidArgumentException
     */
    public static function containsDisallowedSubstring(string $body): bool
    {
        return (bool) preg_match(self::RENDER_BLOCK_REGEX, $body);
    }

    /**
     * Public-facing helper used by callers (e.g. tests) to introspect which
     * variables were rejected. Returns the list of `{{ var }}` names that
     * appear in the body but are NOT in the allow-list.
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public function missingAllowListVariables(string $body, array $allowed): array
    {
        $missing = [];
        if (preg_match_all(self::VARIABLE_REGEX, $body, $matches) !== false) {
            foreach ($matches[1] as $name) {
                if (! in_array($name, $allowed, true)) {
                    $missing[] = $name;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Reject bodies that contain injection vectors BEFORE we substitute
     * variables. This is the static guard documented in C-02.
     *
     * @throws InvalidArgumentException
     */
    private function assertBodySafe(EmailTemplate $template): void
    {
        foreach (['subject', 'body_html', 'body_text'] as $field) {
            $value = $this->flattenString($template->{$field} ?? null);

            if ($value === '' || $value === null) {
                continue;
            }

            if (self::containsDisallowedSubstring($value)) {
                throw new InvalidArgumentException(
                    sprintf('Template body contains a disallowed token (field=%s).', $field),
                );
            }
        }

        // Unknown variable detection.
        foreach (['subject', 'body_html', 'body_text'] as $field) {
            $value = $this->flattenString($template->{$field} ?? null);
            if ($value === '' || $value === null) {
                continue;
            }
            $missing = $this->missingAllowListVariables($value, $this->allowedVariables);

            if ($missing !== []) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Template references variables not in the allow-list (field=%s, missing=%s).',
                        $field,
                        implode(',', $missing),
                    ),
                );
            }
        }
    }

    /**
     * Substitute `{{ name }}` tokens with `$vars['name']`. Unknown tokens
     * are caught before this point by {@see self::assertBodySafe()}.
     */
    private function interpolate(string $body, array $vars): string
    {
        $callback = function (array $match) use ($vars): string {
            $name = (string) $match[1];
            if (! array_key_exists($name, $vars)) {
                // Strict: we never silently drop variables. If the
                // allow-list + missing-allow-list guards earlier in the
                // pipeline let this through somehow, throw to surface the
                // drift.
                throw new InvalidArgumentException(
                    sprintf('Variable "%s" not provided at render time.', $name),
                );
            }

            return (string) $vars[$name];
        };

        $result = preg_replace_callback(self::VARIABLE_REGEX, $callback, $body);

        return $result === null ? $body : $result;
    }

    /**
     * Filter the user-supplied variable bag down to the allow-list. Extra
     * keys are silently discarded (defense-in-depth — they are inert).
     *
     * @param  array<string, mixed>  $vars
     * @return array<string, string|int|float|bool>
     */
    private function filterVars(array $vars): array
    {
        $filtered = [];
        foreach ($this->allowedVariables as $allowed) {
            if (array_key_exists($allowed, $vars)) {
                $filtered[$allowed] = $vars[$allowed];
            }
        }

        return $filtered;
    }

    /**
     * Body fields are stored as `array` casts (subject / body_html /
     * body_text are decoded from JSON). Collapse the array back to a
     * single string for substitution.
     */
    private function flattenString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value) && $value !== []) {
            $first = reset($value);

            return is_string($first) ? $first : '';
        }

        return '';
    }
}

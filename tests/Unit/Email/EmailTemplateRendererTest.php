<?php

declare(strict_types=1);

namespace Tests\Unit\Email;

use App\Models\Email\EmailTemplate;
use App\Services\Email\EmailTemplateRenderer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * B13 Pasada B — Unit tests for {@see EmailTemplateRenderer}.
 *
 * Covers the strict allow-list semantics + the static body-safety guard
 * (decision 11c — no Blade / no PHP / no eval).
 */
class EmailTemplateRendererTest extends TestCase
{
    public function test_renders_a_template_with_known_variables(): void
    {
        $template = $this->makeTemplate([
            'subject' => 'Hola, {{ customer_name }}',
            'body_html' => ['<p>Hola, {{ customer_name }} — propuesta #{{ proposal_id }}</p>'],
            'body_text' => ['Hola, {{ customer_name }} — propuesta #{{ proposal_id }}'],
        ]);

        $renderer = new EmailTemplateRenderer(['customer_name', 'proposal_id']);

        $rendered = $renderer->render($template, [
            'customer_name' => 'Acme',
            'proposal_id' => 'C-7',
        ]);

        $this->assertSame('Hola, Acme', $rendered['subject']);
        $this->assertStringContainsString('Hola, Acme', $rendered['body_html']);
        $this->assertStringContainsString('propuesta #C-7', $rendered['body_text']);
    }

    public function test_it_rejects_an_unknown_variable_in_the_body(): void
    {
        $template = $this->makeTemplate([
            'subject' => '{{ customer_name }}',
            'body_html' => ['<p>{{ unknown_variable }}</p>'],
            'body_text' => ['{{ unknown_variable }}'],
        ]);

        $renderer = new EmailTemplateRenderer(['customer_name']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/allow-list/');

        $renderer->render($template, ['customer_name' => 'Acme']);
    }

    public function test_it_rejects_php_open_tag_in_the_body(): void
    {
        $template = $this->makeTemplate([
            'subject' => 'Hi',
            'body_html' => ['<p>Hola</p><?php echo "evil"; ?>'],
            'body_text' => ['Hola'],
        ]);

        $renderer = new EmailTemplateRenderer([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/disallowed token/');

        $renderer->render($template, []);
    }

    public function test_it_rejects_script_tag_in_the_body(): void
    {
        $template = $this->makeTemplate([
            'subject' => 'Hi',
            'body_html' => ['<p>Hola <script>alert(1)</script></p>'],
            'body_text' => ['Hola'],
        ]);

        $renderer = new EmailTemplateRenderer([]);

        $this->expectException(InvalidArgumentException::class);

        $renderer->render($template, []);
    }

    public function test_it_rejects_eval_call_in_the_body(): void
    {
        $template = $this->makeTemplate([
            'subject' => 'Hi',
            'body_html' => ['<p>Hola eval(</p>'],
            'body_text' => ['Hola'],
        ]);

        $renderer = new EmailTemplateRenderer([]);

        $this->expectException(InvalidArgumentException::class);

        $renderer->render($template, []);
    }

    public function test_it_rejects_blade_render_call_in_the_body(): void
    {
        $template = $this->makeTemplate([
            'subject' => 'Hi',
            'body_html' => ['<p>Hola Blade::render(</p>'],
            'body_text' => ['Hola'],
        ]);

        $renderer = new EmailTemplateRenderer([]);

        $this->expectException(InvalidArgumentException::class);

        $renderer->render($template, []);
    }

    public function test_contains_disallowed_substring_returns_false_for_safe_body(): void
    {
        $this->assertFalse(EmailTemplateRenderer::containsDisallowedSubstring('<p>Hola {{name}}</p>'));
    }

    public function test_contains_disallowed_substring_returns_true_for_unsafe_body(): void
    {
        $this->assertTrue(EmailTemplateRenderer::containsDisallowedSubstring('<p>Hola <?php echo 1; ?></p>'));
        $this->assertTrue(EmailTemplateRenderer::containsDisallowedSubstring('<script>evil()</script>'));
    }

    public function test_renderer_returns_string_payload_with_three_keys(): void
    {
        $template = $this->makeTemplate([
            'subject' => 'S',
            'body_html' => ['<p>B</p>'],
            'body_text' => ['B'],
        ]);

        $renderer = new EmailTemplateRenderer([]);
        $rendered = $renderer->render($template, []);

        $this->assertSame(['subject', 'body_html', 'body_text'], array_keys($rendered));
        $this->assertSame('S', $rendered['subject']);
        $this->assertStringContainsString('<p>B</p>', $rendered['body_html']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTemplate(array $overrides): EmailTemplate
    {
        $template = new EmailTemplate();
        $template->fill($overrides);

        return $template;
    }
}

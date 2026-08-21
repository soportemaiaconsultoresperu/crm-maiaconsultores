<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Email;

use App\Models\Email\EmailTemplate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * B13 Pasada B — Livewire component that hosts the EmailTemplate editor.
 *
 * Dual-purpose (create + edit). The form is rendered by
 * `resources/views/livewire/admin/email/template-form.blade.php` and wraps
 * the entire `<form>` that posts to
 *   - POST admin.email.templates.store       (mode='create')
 *   - PUT  admin.email.templates.update/{id} (mode='edit')
 *
 * Livewire carries the state for inline update of the preview pane
 * (`updatedBodyHtml()`) and for add/remove of variables
 * (`addVariable()` / `removeVariable()`). The host views
 * (`admin/email/templates/create.blade.php` and `…/edit.blade.php`)
 * extend `layouts.app`.
 */
#[Layout('layouts.app')]
class TemplateForm extends Component
{
    public ?int $templateId = null;

    public string $mode = 'create';

    public string $name = '';

    public string $slug = '';

    public string $subject = '';

    public string $bodyHtml = '';

    public string $bodyText = '';

    /**
     * @var list<string>
     */
    public array $variablesArray = [];

    public bool $isActive = false;

    public string $previewSubject = '';

    public string $previewHtml = '';

    public string $previewText = '';

    public function mount(?int $templateId = null, string $mode = 'create'): void
    {
        $this->templateId = $templateId;
        $this->mode = $mode;

        if ($mode === 'edit' && $templateId !== null) {
            $template = EmailTemplate::query()->find($templateId);
            if ($template !== null) {
                $this->name = (string) $template->name;
                $this->slug = (string) $template->slug;
                $this->subject = (string) $template->subject;
                $this->bodyHtml = self::flatten($template->body_html);
                $this->bodyText = self::flatten($template->body_text);
                $this->variablesArray = array_values(array_filter(
                    $template->variables_json ?? [],
                    'is_string',
                ));
                $this->isActive = (bool) $template->is_active;
                $this->refreshPreview();
            }
        }

        if ($this->mode === 'create') {
            $this->refreshPreview();
        }
    }

    public function updatedBodyHtml(): void
    {
        $this->refreshPreview();
    }

    public function updatedSubject(): void
    {
        $this->previewSubject = (string) $this->subject;
    }

    public function addVariable(): void
    {
        $this->variablesArray[] = '';
    }

    public function removeVariable(int $index): void
    {
        if (! isset($this->variablesArray[$index])) {
            return;
        }

        array_splice($this->variablesArray, $index, 1);
    }

    /**
     * Re-render the preview pane using the current body and the test vars
     * (`__preview_*` sentinels). Cheap — does not touch the DB.
     */
    public function refreshPreview(): void
    {
        $testVars = [];
        foreach ($this->variablesArray as $name) {
            if ($name === '') {
                continue;
            }
            $testVars[$name] = '«'.$name.'»';
        }

        try {
            $renderer = new \App\Services\Email\EmailTemplateRenderer(
                array_values(array_filter($this->variablesArray, fn ($v) => $v !== '')),
            );

            $template = new EmailTemplate();
            $template->fill([
                'subject' => $this->subject,
                'body_html' => [$this->bodyHtml],
                'body_text' => [$this->bodyText],
            ]);

            $rendered = $renderer->render($template, $testVars);

            $this->previewSubject = $rendered['subject'];
            $this->previewHtml = $rendered['body_html'];
            $this->previewText = $rendered['body_text'];
        } catch (\InvalidArgumentException $e) {
            $this->previewSubject = '(error en vista previa)';
            $this->previewHtml = '<p class="text-danger">'.$e->getMessage().'</p>';
            $this->previewText = $e->getMessage();
        }
    }

    public function render()
    {
        return view('livewire.admin.email.template-form');
    }

    private static function flatten(mixed $value): string
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

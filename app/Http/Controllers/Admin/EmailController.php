<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Email\SendEmailRequest;
use App\Http\Requests\Admin\Email\StoreTemplateRequest;
use App\Http\Requests\Admin\Email\UpdateTemplateRequest;
use App\Jobs\V2\SendEmailMessage;
use App\Models\Email\EmailMessage;
use App\Models\Email\EmailTemplate;
use App\Models\Email\EmailTemplateVersion;
use App\Models\IntegrationAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * B13 Pasada B — Admin controller for email templates and accounts.
 *
 * Surface:
 *   - index/create/edit/update/destroy     — EmailTemplate CRUD (email.template.manage)
 *   - accounts                              — IntegrationAccount list (email.account.manage)
 *   - send                                  — preview-then-send test-send (email.send)
 *
 * The Livewire form (`Admin\Email\TemplateForm`) is hosted by the
 * `create()` and `edit()` views; the form submits to `store()` and
 * `update()` via standard HTTP POST / PUT.
 */
class EmailController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('email.view');

        $templates = EmailTemplate::query()
            ->with('creator')
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.email.templates.index', [
            'templates' => $templates,
        ]);
    }

    public function create(): View
    {
        Gate::authorize('email.template.manage');

        return view('admin.email.templates.create');
    }

    public function store(StoreTemplateRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $template = DB::transaction(function () use ($data, $request): EmailTemplate {
            $template = EmailTemplate::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'subject' => $data['subject'],
                'body_html' => $data['body_html'],
                'body_text' => $data['body_text'],
                'variables_json' => $data['variables_json'] ?? [],
                'is_active' => (bool) ($data['is_active'] ?? false),
                'version' => 1,
                'created_by' => $request->user()?->id,
            ]);

            EmailTemplateVersion::create([
                'template_id' => $template->id,
                'version' => 1,
                'subject' => $template->subject,
                'body_html' => $template->body_html,
                'body_text' => $template->body_text,
                'variables_json' => $template->variables_json,
                'snapshot_by' => $request->user()?->id,
                'created_at' => now(),
            ]);

            return $template;
        });

        return redirect()
            ->route('admin.email.templates.edit', $template)
            ->with('success', 'Plantilla creada.');
    }

    public function edit(EmailTemplate $template): View
    {
        Gate::authorize('email.template.manage');

        return view('admin.email.templates.edit', [
            'template' => $template,
        ]);
    }

    public function update(UpdateTemplateRequest $request, EmailTemplate $template): RedirectResponse
    {
        $data = $request->validated();
        $previousBody = $template->body_html;

        DB::transaction(function () use ($data, $request, $template, $previousBody): void {
            $template->fill([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'subject' => $data['subject'],
                'body_html' => $data['body_html'],
                'body_text' => $data['body_text'],
                'variables_json' => $data['variables_json'] ?? $template->variables_json,
                'is_active' => (bool) ($data['is_active'] ?? $template->is_active),
            ]);
            $template->save();

            if ($previousBody !== $template->body_html) {
                $nextVersion = (int) (EmailTemplateVersion::query()
                    ->where('template_id', $template->id)
                    ->max('version') ?? $template->version) + 1;

                EmailTemplateVersion::create([
                    'template_id' => $template->id,
                    'version' => $nextVersion,
                    'subject' => $template->subject,
                    'body_html' => $template->body_html,
                    'body_text' => $template->body_text,
                    'variables_json' => $template->variables_json,
                    'snapshot_by' => $request->user()?->id,
                    'created_at' => now(),
                ]);

                $template->forceFill(['version' => $nextVersion])->save();
            }
        });

        return redirect()
            ->route('admin.email.templates.edit', $template)
            ->with('success', 'Plantilla actualizada.');
    }

    public function destroy(EmailTemplate $template): RedirectResponse
    {
        Gate::authorize('email.template.manage');

        $template->delete();

        return redirect()
            ->route('admin.email.templates.index')
            ->with('success', 'Plantilla enviada a la papelera.');
    }

    public function accounts(Request $request): View
    {
        Gate::authorize('email.account.manage');

        $accounts = IntegrationAccount::query()
            ->whereIn('provider', ['smtp', 'gmail', 'outlook'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.email.accounts.index', [
            'accounts' => $accounts,
        ]);
    }

    /**
     * Preview-then-send test-send endpoint (decision 11b).
     * Renders the template against the supplied variables and dispatches
     * the SendEmailMessage job with the rendered payload + the user-entered
     * override recipient. Gated `email.send`.
     */
    public function send(SendEmailRequest $request, EmailTemplate $template): RedirectResponse
    {
        $data = $request->validated();

        $variables = is_array($data['variables'] ?? null) ? $data['variables'] : [];
        $renderer = new \App\Services\Email\EmailTemplateRenderer(
            $template->variables_json ?? [],
        );
        $rendered = $renderer->render($template, $variables);

        $message = \App\Models\Email\EmailMessage::create([
            'account_id' => $data['account_id'] ?? null,
            'direction' => EmailMessage::DIRECTION_OUTBOUND,
            'provider_message_id' => 'live-'.bin2hex(random_bytes(8)),
            'from_email' => (string) config('mail.from.address'),
            'from_name' => (string) config('mail.from.name'),
            'subject' => $data['subject'] ?? $rendered['subject'],
            'body_html' => [$rendered['body_html']],
            'body_text' => [$rendered['body_text']],
            'status' => EmailMessage::STATUS_QUEUED,
            'created_by' => $request->user()?->id,
        ]);

        $message->participants()->create([
            'kind' => \App\Models\Email\EmailParticipant::KIND_TO,
            'email' => $data['to'],
            'name' => null,
        ]);

        SendEmailMessage::dispatch($message->id);

        return redirect()
            ->route('admin.email.templates.edit', $template)
            ->with('success', 'Envío encolado a '.$data['to'].'.');
    }
}

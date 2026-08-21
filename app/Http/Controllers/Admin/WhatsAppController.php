<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\V2\SendWhatsAppMessage;
use App\Models\User as UserModel;
use App\Models\WhatsApp\WhatsAppAccount;
use App\Models\WhatsApp\WhatsAppConsentLog;
use App\Models\WhatsApp\WhatsAppConversation;
use App\Models\WhatsApp\WhatsAppMessage;
use App\Models\WhatsApp\WhatsAppTemplate;
use App\Services\DataScopeService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * B14 Pasada B-2 — Admin controller for the WhatsApp inbox + catalogue.
 *
 * Surface:
 *   - accounts / showAccount           WhatsAppAccount list & detail (whatsapp.view)
 *   - conversations / showConversation WhatsAppConversation inbox + detail (whatsapp.view)
 *   - sendMessage                      outbound free-form send (whatsapp.send)
 *   - assignConversation               assign user to a thread (whatsapp.conversation.assign)
 *   - closeConversation                mark status=closed (whatsapp.send — see note)
 *   - markOptOut                       consent log + opt-out flag (whatsapp.send)
 *   - templates / showTemplate         catalogue (whatsapp.view)
 *   - triggerTemplateSync              run syncTemplates for one account (whatsapp.template.manage)
 *
 * Close / opt-out use `whatsapp.send` as the gate: anyone allowed to push a
 * message must also be able to terminate the conversation or record an
 * opt-out. A dedicated `whatsapp.conversation.manage` permission would be
 * the next step (deferred to a future release).
 *
 * Data scope (decisions 14a-c) is enforced inside
 * {@see conversations()} / {@see showConversation()} and again inside
 * {@see assignConversation()} for the assignee check. Per D-14b, unassigned
 * conversations are also visible to non-admin viewers — every seller in the
 * same team can claim them.
 */
class WhatsAppController extends Controller
{
    public function __construct(
        private readonly DataScopeService $scope,
        private readonly WhatsAppService $service,
    ) {
    }

    public function accounts(Request $request): View
    {
        Gate::authorize('whatsapp.view');

        $accounts = WhatsAppAccount::query()
            ->orderByDesc('id')
            ->paginate(20);

        return view('admin.whatsapp.accounts.index', [
            'accounts' => $accounts,
        ]);
    }

    public function showAccount(WhatsAppAccount $account): View
    {
        Gate::authorize('whatsapp.view');

        $recentConversations = WhatsAppConversation::query()
            ->where('account_id', $account->getKey())
            ->orderByDesc('last_message_at')
            ->limit(20)
            ->get();

        return view('admin.whatsapp.accounts.show', [
            'account' => $account,
            'recentConversations' => $recentConversations,
        ]);
    }

    public function conversations(Request $request): View
    {
        Gate::authorize('whatsapp.view');

        $user = $request->user();
        $query = WhatsAppConversation::query()
            ->with(['account:id,display_name,phone_number', 'assignee:id,name']);

        // DataScope (decisions 14a-c): admin sees everything; supervisor /
        // vendedor see their own assigned conversations (and the team
        // members' conversations). Strict DataScope per the B14 brief —
        // unassigned conversations are NOT surfaced to non-admin viewers
        // at the inbox level (D-14b applies once a conversation has been
        // claimed by a team seller).
        $visibleOwnerIds = $this->scope->visibleOwnerIds($user);
        if ($visibleOwnerIds !== null) {
            $query->whereIn('assigned_to', $visibleOwnerIds);
        }

        $statusFilter = $request->query('status');
        if (in_array($statusFilter, [
            WhatsAppConversation::STATUS_OPEN,
            WhatsAppConversation::STATUS_CLOSED,
            WhatsAppConversation::STATUS_ARCHIVED,
        ], true)) {
            $query->where('status', $statusFilter);
        } elseif ($statusFilter === 'opted_out') {
            // Filter shorthand: ?status=opted_out → opt_out_at IS NOT NULL.
            $query->whereNotNull('opt_out_at');
        }

        if ($assignedTo = $request->query('assigned_to')) {
            $query->where('assigned_to', (int) $assignedTo);
        }

        if ($phoneFilter = trim((string) $request->query('phone_number'))) {
            $query->where('phone_number', 'like', '%'.$phoneFilter.'%');
        }

        $query->orderByDesc('last_message_at')->orderByDesc('id');

        $conversations = $query->paginate(20)->withQueryString();

        return view('admin.whatsapp.conversations.index', [
            'conversations' => $conversations,
            'filters' => [
                'status' => $statusFilter,
                'assigned_to' => $assignedTo,
                'phone_number' => $phoneFilter,
            ],
        ]);
    }

    public function showConversation(WhatsAppConversation $conversation): View
    {
        Gate::authorize('whatsapp.view');

        $user = request()->user();
        $visibleOwnerIds = $this->scope->visibleOwnerIds($user);
        if ($visibleOwnerIds !== null) {
            $isAssignedToVisible = in_array((int) $conversation->assigned_to, $visibleOwnerIds, true);
            $isUnassigned = $conversation->assigned_to === null;
            abort_unless($isAssignedToVisible || $isUnassigned, 403);
        }

        $messages = WhatsAppMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->with('template:id,name,language')
            ->orderBy('id')
            ->paginate(50);

        return view('admin.whatsapp.conversations.show', [
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    public function sendMessage(Request $request, WhatsAppConversation $conversation): RedirectResponse
    {
        Gate::authorize('whatsapp.send');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
        ]);

        if ($conversation->opt_out_at !== null) {
            return redirect()
                ->route('whatsapp.conversations.show', $conversation)
                ->with('error', 'La conversación registró un opt-out; no se puede enviar.');
        }

        $message = WhatsAppMessage::create([
            'conversation_id' => $conversation->getKey(),
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => 'freeform',
            'body' => $data['body'],
            'status' => WhatsAppMessage::STATUS_QUEUED,
            'provider_message_id' => 'live-'.$conversation->getKey().'-'.bin2hex(random_bytes(6)),
        ]);

        $conversation->forceFill([
            'last_message_at' => now(),
            'last_direction' => WhatsAppConversation::DIRECTION_OUTBOUND,
        ])->save();

        SendWhatsAppMessage::dispatch($message->getKey());

        return redirect()
            ->route('whatsapp.conversations.show', $conversation)
            ->with('success', 'Mensaje encolado.');
    }

    public function assignConversation(Request $request, WhatsAppConversation $conversation): RedirectResponse
    {
        Gate::authorize('whatsapp.conversation.assign');

        $data = $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
        ]);

        $assignee = UserModel::query()->findOrFail($data['assigned_to']);

        // DataScope: the actor can only assign to users inside their own
        // visibility scope (decision 9: "Asignación siempre respeta
        // DataScopeService").
        $visible = $this->scope->visibleOwnerIds($request->user());
        abort_unless(
            $visible === null || in_array((int) $assignee->id, $visible, true),
            403,
        );

        $conversation->forceFill(['assigned_to' => $assignee->id])->save();

        return redirect()
            ->route('whatsapp.conversations.show', $conversation)
            ->with('success', "Conversación asignada a {$assignee->name}.");
    }

    public function closeConversation(WhatsAppConversation $conversation): RedirectResponse
    {
        Gate::authorize('whatsapp.send');

        $conversation->forceFill(['status' => WhatsAppConversation::STATUS_CLOSED])->save();

        return redirect()
            ->route('whatsapp.conversations.index')
            ->with('success', "Conversación {$conversation->id} cerrada.");
    }

    public function markOptOut(WhatsAppConversation $conversation): RedirectResponse
    {
        Gate::authorize('whatsapp.send');

        $now = now();

        if ($conversation->contact_id === null) {
            return redirect()
                ->route('whatsapp.conversations.show', $conversation)
                ->with('error', 'La conversación no tiene un contacto asociado; no se puede registrar el opt-out.');
        }

        WhatsAppConsentLog::create([
            'contact_id' => $conversation->contact_id,
            'conversation_id' => $conversation->getKey(),
            'type' => WhatsAppConsentLog::TYPE_OPT_OUT,
            'source' => 'admin',
            'revoked_at' => $now,
        ]);

        $conversation->forceFill([
            'status' => 'opted_out',
            'opt_out_at' => $now,
        ])->save();

        return redirect()
            ->route('whatsapp.conversations.show', $conversation)
            ->with('success', 'Opt-out registrado para la conversación.');
    }

    public function templates(Request $request): View
    {
        Gate::authorize('whatsapp.view');

        $query = WhatsAppTemplate::query()
            ->with('account:id,display_name,phone_number');

        if ($accountId = $request->query('account_id')) {
            $query->where('account_id', (int) $accountId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        $query->orderByDesc('id');

        $templates = $query->paginate(20)->withQueryString();

        return view('admin.whatsapp.templates.index', [
            'templates' => $templates,
            'filters' => [
                'account_id' => $accountId,
                'status' => $status,
                'category' => $category,
            ],
            'statuses' => [
                WhatsAppTemplate::STATUS_DRAFT,
                WhatsAppTemplate::STATUS_PENDING,
                WhatsAppTemplate::STATUS_APPROVED,
                WhatsAppTemplate::STATUS_REJECTED,
                WhatsAppTemplate::STATUS_DISABLED,
            ],
        ]);
    }

    public function showTemplate(WhatsAppTemplate $template): View
    {
        Gate::authorize('whatsapp.view');

        $recentMessages = WhatsAppMessage::query()
            ->where('template_id', $template->getKey())
            ->with('conversation:id,phone_number,contact_name')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('admin.whatsapp.templates.show', [
            'template' => $template,
            'recentMessages' => $recentMessages,
        ]);
    }

    public function triggerTemplateSync(Request $request, WhatsAppAccount $account): RedirectResponse
    {
        Gate::authorize('whatsapp.template.manage');

        $count = $this->service->syncTemplates($account);

        return redirect()
            ->route('whatsapp.accounts.show', $account)
            ->with('success', "Sincronización completada. {$count} plantilla(s) aprobadas.");
    }
}
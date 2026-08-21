<?php

declare(strict_types=1);

namespace App\Livewire\Admin\WhatsApp;

use App\Models\WhatsApp\WhatsAppConversation;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * B14 Pasada B-2 — Livewire component that backs the conversations inbox.
 *
 * Hosts:
 *   - `resources/views/livewire/admin/whatsapp/conversation-list.blade.php`
 *   - rendered by `resources/views/admin/whatsapp/conversations/index.blade.php`
 *
 * State:
 *   - $statusFilter / $assignedToFilter / $phoneFilter — filter form
 *   - $page — pagination cursor (Livewire handles binding via WithPagination)
 *
 * The component does NOT mutate conversations directly: every state change
 * (assign / close / opt-out) is a POST to the canonical
 * {@see \App\Http\Controllers\Admin\WhatsAppController} endpoints, which
 * enforce permission gates + DataScope. After a successful POST the
 * Livewire view re-renders and the user sees the updated row.
 */
class ConversationList extends Component
{
    use WithPagination;

    public ?string $statusFilter = null;

    public ?int $assignedToFilter = null;

    public ?string $phoneFilter = null;

    public function mount(): void
    {
        // The filter values are bound to the URL via querystring (the host
        // view posts the filter form to itself with ?status=… etc.).
        $this->statusFilter = request()->query('status') ?: null;
        $this->assignedToFilter = request()->query('assigned_to')
            ? (int) request()->query('assigned_to')
            : null;
        $this->phoneFilter = request()->query('phone_number') ?: null;
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedAssignedToFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPhoneFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Triggered by the "Asignar" inline button on a row. Delegates the
     * write to the controller endpoint so the permission gate
     * (`whatsapp.conversation.assign`) and the DataScope check remain in
     * the canonical layer (Livewire must not become a write back-door).
     */
    public function assignConversation(int $conversationId, int $userId): void
    {
        $conversation = WhatsAppConversation::query()->find($conversationId);
        if ($conversation === null) {
            return;
        }

        if (Gate::forUser(Auth::user())->denies('whatsapp.conversation.assign')) {
            $this->addError('assign', 'No tienes permiso para asignar conversaciones.');

            return;
        }

        $conversation->forceFill(['assigned_to' => $userId])->save();
        $this->dispatch('conversation-assigned', id: $conversationId);
    }

    /**
     * Triggered by the "Cerrar" inline button. Mirrors the controller
     * POST endpoint so the UX stays consistent without leaving the inbox.
     */
    public function closeConversation(int $conversationId): void
    {
        $conversation = WhatsAppConversation::query()->find($conversationId);
        if ($conversation === null) {
            return;
        }

        if (Gate::forUser(Auth::user())->denies('whatsapp.send')) {
            $this->addError('close', 'No tienes permiso para cerrar conversaciones.');

            return;
        }

        $conversation->forceFill(['status' => WhatsAppConversation::STATUS_CLOSED])->save();
        $this->dispatch('conversation-closed', id: $conversationId);
    }

    public function render(): View
    {
        Gate::authorize('whatsapp.view');

        $user = Auth::user();
        $query = WhatsAppConversation::query()
            ->with(['account:id,display_name,phone_number', 'assignee:id,name']);

        // DataScope (decisions 14a-c) — same filter as the controller so
        // the inbox view and the Livewire-driven row actions agree.
        // Strict DataScope per the B14 brief: unassigned conversations
        // are NOT surfaced to non-admin viewers at the inbox level.
        $visibleOwnerIds = app(\App\Services\DataScopeService::class)->visibleOwnerIds($user);
        if ($visibleOwnerIds !== null) {
            $query->whereIn('assigned_to', $visibleOwnerIds);
        }

        if ($this->statusFilter !== null && $this->statusFilter !== '') {
            if ($this->statusFilter === 'opted_out') {
                $query->whereNotNull('opt_out_at');
            } else {
                $query->where('status', $this->statusFilter);
            }
        }

        if ($this->assignedToFilter !== null) {
            $query->where('assigned_to', $this->assignedToFilter);
        }

        if ($this->phoneFilter !== null && $this->phoneFilter !== '') {
            $query->where('phone_number', 'like', '%'.$this->phoneFilter.'%');
        }

        $conversations = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.admin.whatsapp.conversation-list', [
            'conversations' => $conversations,
            'canSend' => $user !== null && Gate::forUser($user)->allows('whatsapp.send'),
            'canAssign' => $user !== null && Gate::forUser($user)->allows('whatsapp.conversation.assign'),
            'users' => \App\Models\User::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }
}
<?php

declare(strict_types=1);

namespace App\Livewire\Admin\WhatsApp;

use App\Jobs\V2\SendWhatsAppMessage;
use App\Models\WhatsApp\WhatsAppConversation;
use App\Models\WhatsApp\WhatsAppMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * B14 Pasada B-2 — Livewire component for the conversation messages pane.
 *
 * Hosts:
 *   - `resources/views/livewire/admin/whatsapp/message-list.blade.php`
 *   - rendered by `resources/views/admin/whatsapp/conversations/show.blade.php`
 *
 * State:
 *   - $conversationId — bound by the host view via :conversation-id="$conversation->id"
 *   - $body — current draft of the outbound message
 *   - $sending — guards the submit button so users can't double-fire while the
 *     POST to the controller is in flight
 *
 * `send()` mirrors the canonical {@see \App\Http\Controllers\Admin\WhatsAppController::sendMessage()}
 * business logic — same permission gate (`whatsapp.send`), same opt-out
 * check, same dispatch of {@see \App\Jobs\V2\SendWhatsAppMessage}. The
 * controller remains the single source of truth (used by the HTTP POST
 * form); the Livewire variant exists for the textarea-on-the-page UX.
 */
class MessageList extends Component
{
    use WithPagination;

    public int $conversationId;

    public string $body = '';

    public bool $sending = false;

    public function mount(int $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    /**
     * Public action exposed to the view: re-read messages from the DB.
     * Cheap — used after a successful send to repaint the bubble list.
     */
    public function loadMessages(): void
    {
        $this->resetPage();
    }

    /**
     * Submits the draft to the canonical controller action. The submit
     * button stays disabled until the response is back so the user cannot
     * double-fire while the request is in flight.
     */
    public function send(): void
    {
        if ($this->sending) {
            return;
        }

        if (trim($this->body) === '') {
            session()->flash('error', 'Escribe un mensaje antes de enviar.');

            return;
        }

        $conversation = WhatsAppConversation::query()->find($this->conversationId);
        if ($conversation === null) {
            session()->flash('error', 'La conversación ya no existe.');

            return;
        }

        if (Gate::forUser(Auth::user())->denies('whatsapp.send')) {
            session()->flash('error', 'No tienes permiso para enviar mensajes.');

            return;
        }

        if ($conversation->opt_out_at !== null) {
            session()->flash('error', 'La conversación registró un opt-out; no se puede enviar.');

            return;
        }

        $this->sending = true;

        try {
            // Mirror the controller's sendMessage() — same row, same job.
            $message = WhatsAppMessage::create([
                'conversation_id' => $conversation->getKey(),
                'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
                'type' => 'freeform',
                'body' => $this->body,
                'status' => WhatsAppMessage::STATUS_QUEUED,
                'provider_message_id' => 'live-'.$conversation->getKey().'-'.bin2hex(random_bytes(6)),
            ]);

            $conversation->forceFill([
                'last_message_at' => now(),
                'last_direction' => WhatsAppConversation::DIRECTION_OUTBOUND,
            ])->save();

            SendWhatsAppMessage::dispatch($message->getKey());
        } finally {
            $this->sending = false;
            $this->body = '';
            $this->loadMessages();
        }
    }

    public function render(): View
    {
        Gate::authorize('whatsapp.view');

        $conversation = WhatsAppConversation::query()
            ->with(['account:id,display_name,phone_number', 'assignee:id,name'])
            ->find($this->conversationId);

        $messages = WhatsAppMessage::query()
            ->where('conversation_id', $this->conversationId)
            ->with('template:id,name,language')
            ->orderBy('id')
            ->paginate(50);

        return view('livewire.admin.whatsapp.message-list', [
            'conversation' => $conversation,
            'messages' => $messages,
            'canSend' => Auth::user() !== null
                && Gate::forUser(Auth::user())->allows('whatsapp.send')
                && ($conversation?->opt_out_at === null),
        ]);
    }
}
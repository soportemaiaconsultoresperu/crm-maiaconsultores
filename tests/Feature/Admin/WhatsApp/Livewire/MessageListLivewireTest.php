<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\WhatsApp\Livewire;

use App\Contracts\WhatsApp\WhatsAppProviderFactory;
use App\Jobs\V2\SendWhatsAppMessage;
use App\Livewire\Admin\WhatsApp\MessageList;
use App\Models\User;
use App\Models\WhatsApp\WhatsAppAccount;
use App\Models\WhatsApp\WhatsAppConversation;
use App\Models\WhatsApp\WhatsAppMessage;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B14 Pasada B-2 — MessageList Livewire component tests.
 *
 * Covers:
 *  - mount($conversationId) loads existing messages.
 *  - send() delegates to the controller endpoint: a queued outbound
 *    WhatsAppMessage is created, the SendWhatsAppMessage job is dispatched,
 *    and the textarea is cleared.
 */
class MessageListLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->register(\App\Providers\WhatsAppServiceProvider::class, force: true);
    }

    public function test_mount_loads_existing_messages(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppMessage::DIRECTION_INBOUND,
            'type' => 'freeform',
            'body' => 'Hola, necesito información',
            'status' => WhatsAppMessage::STATUS_QUEUED,
            'provider_message_id' => 'inb-1',
        ]);

        Livewire::actingAs($admin)
            ->test(MessageList::class, ['conversationId' => $conversation->id])
            ->assertSet('conversationId', $conversation->id)
            ->assertSet('body', '')
            ->assertSee('Hola, necesito información');
    }

    public function test_send_delegates_to_controller_and_actually_delivers_the_message(): void
    {
        Bus::fake();
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.LIVEWIRE-OK']],
            ], 200),
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeSendableAccount();
        $conversation = $this->makeConversation($account);

        Livewire::actingAs($admin)
            ->test(MessageList::class, ['conversationId' => $conversation->id])
            ->set('body', 'Gracias por contactarnos')
            ->call('send')
            ->assertSet('body', '');

        $this->assertDatabaseHas('whatsapp_messages', [
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => 'freeform',
            'body' => 'Gracias por contactarnos',
        ]);

        Bus::assertDispatched(SendWhatsAppMessage::class);

        /** @var WhatsAppMessage $message */
        $message = WhatsAppMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', WhatsAppMessage::DIRECTION_OUTBOUND)
            ->firstOrFail();

        // E-3: executing the job is what exposes the defect. Asserting `queued`
        // plus the dispatch was the defect's hiding place — the job marked
        // every free-form message failed (NoTemplate) without sending it.
        (new SendWhatsAppMessage($message->id))->handle(app(WhatsAppProviderFactory::class));

        $message->refresh();
        $this->assertSame(WhatsAppMessage::STATUS_SENT, $message->status);
        $this->assertSame('wamid.LIVEWIRE-OK', $message->wamid);
        $this->assertNotNull($message->sent_at);
    }

    public function test_send_is_blocked_without_whatsapp_send_permission(): void
    {
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');
        // Grant whatsapp.view so the Livewire render() doesn't 403 — the
        // gate inside send() still denies whatsapp.send and the message
        // never gets persisted.
        $vendedor->givePermissionTo('whatsapp.view');

        $account = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        Livewire::actingAs($vendedor)
            ->test(MessageList::class, ['conversationId' => $conversation->id])
            ->set('body', 'Hola')
            ->call('send');

        $this->assertDatabaseMissing('whatsapp_messages', [
            'conversation_id' => $conversation->id,
            'body' => 'Hola',
        ]);
    }

    private function makeAccount(): WhatsAppAccount
    {
        $account = new WhatsAppAccount([
            'phone_number' => '+15551234567',
            'phone_number_id' => '1234567890',
            'display_name' => 'Test Account',
            'status' => WhatsAppAccount::STATUS_VERIFIED,
        ]);
        $account->save();

        return $account;
    }

    /**
     * Real-mode account (credentials configured) so the outbound job actually
     * performs the Graph API POST instead of returning the stub envelope.
     */
    private function makeSendableAccount(): WhatsAppAccount
    {
        $account = new WhatsAppAccount([
            'phone_number' => '+15551234567',
            'phone_number_id' => '1234567890',
            'business_id' => 'access-token',
            'display_name' => 'Test Account',
            'status' => WhatsAppAccount::STATUS_VERIFIED,
        ]);
        $account->save();

        return $account;
    }

    private function makeConversation(WhatsAppAccount $account, array $overrides = []): WhatsAppConversation
    {
        $conversation = new WhatsAppConversation(array_merge([
            'account_id' => $account->id,
            'phone_number' => '+15550000000',
            'contact_name' => 'Contacto de prueba',
            'status' => WhatsAppConversation::STATUS_OPEN,
            'last_direction' => WhatsAppConversation::DIRECTION_INBOUND,
            'last_message_at' => now(),
        ], $overrides));
        $conversation->save();

        return $conversation;
    }
}
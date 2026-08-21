<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\WhatsApp\Livewire;

use App\Livewire\Admin\WhatsApp\ConversationList;
use App\Models\User;
use App\Models\WhatsApp\WhatsAppAccount;
use App\Models\WhatsApp\WhatsAppConversation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * B14 Pasada B-2 — ConversationList Livewire component tests.
 *
 * Covers:
 *  - mount() defaults filters to null.
 *  - updatedStatusFilter() resets pagination so the user lands on page 1.
 *  - assignConversation() requires whatsapp.conversation.assign and
 *    writes the assigned_to column.
 *  - closeConversation() requires whatsapp.send and flips status='closed'.
 */
class ConversationListLivewireTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->register(\App\Providers\WhatsAppServiceProvider::class, force: true);
    }

    public function test_mount_defaults_filters_to_null(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        Livewire::actingAs($admin)
            ->test(ConversationList::class)
            ->assertSet('statusFilter', null)
            ->assertSet('assignedToFilter', null)
            ->assertSet('phoneFilter', null);
    }

    public function test_updated_status_filter_resets_pagination(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        // Move to page 3 first, then change the filter — the component
        // must reset the pagination cursor back to 1.
        $component = Livewire::actingAs($admin)
            ->test(ConversationList::class)
            ->call('gotoPage', 3);

        $this->assertSame(3, $component->get('paginators.page'));

        $component->set('statusFilter', 'open');

        // paginators['page'] resets to 1 via updatedStatusFilter() hook.
        $this->assertSame(1, $component->get('paginators.page'));
    }

    public function test_assign_conversation_requires_assign_permission(): void
    {
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');
        // Grant whatsapp.view so render() doesn't 403 and corrupt the
        // snapshot before the method runs. whatsapp.conversation.assign
        // stays denied to exercise the gate inside assignConversation().
        $vendedor->givePermissionTo('whatsapp.view');

        $account = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $assignee = User::factory()->create(['is_active' => true]);

        $component = Livewire::actingAs($vendedor)
            ->test(ConversationList::class);

        $component->call('assignConversation', $conversation->id, $assignee->id);

        // No write happened — vendedor lacks whatsapp.conversation.assign.
        $conversation->refresh();
        $this->assertNull($conversation->assigned_to);
    }

    public function test_assign_conversation_writes_assigned_to_when_permitted(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $assignee = User::factory()->create(['is_active' => true]);

        Livewire::actingAs($admin)
            ->test(ConversationList::class)
            ->call('assignConversation', $conversation->id, $assignee->id);

        $conversation->refresh();
        $this->assertSame($assignee->id, (int) $conversation->assigned_to);
    }

    public function test_close_conversation_marks_status_closed(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeAccount();
        $conversation = $this->makeConversation($account, [
            'status' => WhatsAppConversation::STATUS_OPEN,
        ]);

        Livewire::actingAs($admin)
            ->test(ConversationList::class)
            ->call('closeConversation', $conversation->id);

        $conversation->refresh();
        $this->assertSame(WhatsAppConversation::STATUS_CLOSED, $conversation->status);
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
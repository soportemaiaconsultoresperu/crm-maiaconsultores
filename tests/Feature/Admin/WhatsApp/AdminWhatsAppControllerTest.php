<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\WhatsApp;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\Team;
use App\Models\User;
use App\Models\WhatsApp\WhatsAppAccount;
use App\Models\WhatsApp\WhatsAppConsentLog;
use App\Models\WhatsApp\WhatsAppConversation;
use App\Models\WhatsApp\WhatsAppMessage;
use App\Models\WhatsApp\WhatsAppTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B14 Pasada B-2 — Admin WhatsAppController HTTP + permission gate tests.
 *
 * Covers:
 *  - accounts index / show are gated whatsapp.view.
 *  - conversations index supports the four filters + DataScope visibility.
 *  - showConversation loads messages.
 *  - sendMessage creates a queued outbound WhatsAppMessage and returns a redirect.
 *  - assignConversation requires whatsapp.conversation.assign + DataScope
 *    on the assignee (manager above in org tree).
 *  - closeConversation transitions status to closed.
 *  - markOptOut creates a WhatsAppConsentLog row + flips conversation status.
 *  - triggerTemplateSync returns a redirect and runs the service synchronously.
 *  - templates index filters by account/status/category.
 *  - showTemplate renders body + recent messages using it.
 */
class AdminWhatsAppControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        app()->register(\App\Providers\WhatsAppServiceProvider::class, force: true);
    }

    public function test_accounts_index_requires_view_permission(): void
    {
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');

        // vendedor lacks whatsapp.view by default.
        $this->actingAs($vendedor)
            ->get('/admin/whatsapp/accounts')
            ->assertForbidden();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/admin/whatsapp/accounts')
            ->assertOk();
    }

    public function test_accounts_show_returns_404_for_unknown_account(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get('/admin/whatsapp/accounts/999999')
            ->assertNotFound();
    }

    public function test_accounts_show_renders_recent_conversations(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeAccount();
        $this->makeConversation($account, ['phone_number' => '+15551111111']);
        $this->makeConversation($account, ['phone_number' => '+15552222222']);

        $response = $this->actingAs($admin)
            ->get('/admin/whatsapp/accounts/'.$account->id);

        $response->assertOk();
        $response->assertSee('+15551111111');
        $response->assertSee('+15552222222');
    }

    public function test_conversations_index_filters_by_status(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeAccount();
        $open = $this->makeConversation($account, [
            'phone_number' => '+15551111111',
            'status' => WhatsAppConversation::STATUS_OPEN,
        ]);
        $closed = $this->makeConversation($account, [
            'phone_number' => '+15552222222',
            'status' => WhatsAppConversation::STATUS_CLOSED,
        ]);

        $response = $this->actingAs($admin)
            ->get('/admin/whatsapp/conversations?status=open');

        $response->assertOk();
        $response->assertSee('+15551111111');
        $response->assertDontSee('+15552222222');

        $response = $this->actingAs($admin)
            ->get('/admin/whatsapp/conversations?status=closed');

        $response->assertOk();
        $response->assertSee('+15552222222');
        $response->assertDontSee('+15551111111');
    }

    public function test_conversations_index_filters_by_assigned_to(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $userA = User::factory()->create(['is_active' => true]);
        $userB = User::factory()->create(['is_active' => true]);

        $account = $this->makeAccount();
        $this->makeConversation($account, [
            'phone_number' => '+15551111111',
            'assigned_to' => $userA->id,
        ]);
        $this->makeConversation($account, [
            'phone_number' => '+15552222222',
            'assigned_to' => $userB->id,
        ]);

        $response = $this->actingAs($admin)
            ->get('/admin/whatsapp/conversations?assigned_to='.$userA->id);

        $response->assertOk();
        $response->assertSee('+15551111111');
        $response->assertDontSee('+15552222222');
    }

    public function test_conversations_index_filters_by_phone_number_like(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeAccount();
        $this->makeConversation($account, ['phone_number' => '+15559999999']);
        $this->makeConversation($account, ['phone_number' => '+15558888888']);

        $response = $this->actingAs($admin)
            ->get('/admin/whatsapp/conversations?phone_number=9999');

        $response->assertOk();
        $response->assertSee('+15559999999');
        $response->assertDontSee('+15558888888');
    }

    public function test_conversations_index_enforces_data_scope_for_vendedor(): void
    {
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');
        // Grant whatsapp.view so the vendedor can reach the index. Without
        // this the gate would 403 before the DataScope filter even runs.
        $vendedor->givePermissionTo('whatsapp.view');

        $other = User::factory()->create(['is_active' => true]);

        $account = $this->makeAccount();
        $mine = $this->makeConversation($account, [
            'phone_number' => '+15551111111',
            'assigned_to' => $vendedor->id,
        ]);
        $theirs = $this->makeConversation($account, [
            'phone_number' => '+15552222222',
            'assigned_to' => $other->id,
        ]);
        $unassigned = $this->makeConversation($account, [
            'phone_number' => '+15553333333',
            'assigned_to' => null,
        ]);

        $response = $this->actingAs($vendedor)
            ->get('/admin/whatsapp/conversations');

        $response->assertOk();
        $response->assertSee('+15551111111');
        $response->assertDontSee('+15552222222');
        $response->assertDontSee('+15553333333');
    }

    public function test_show_conversation_renders_messages(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppMessage::DIRECTION_INBOUND,
            'type' => 'freeform',
            'body' => 'Hola vengo del cliente',
            'status' => WhatsAppMessage::STATUS_QUEUED,
            'provider_message_id' => 'inb-1',
        ]);
        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => 'freeform',
            'body' => 'Bienvenido, gracias por escribir',
            'status' => WhatsAppMessage::STATUS_SENT,
            'provider_message_id' => 'outb-1',
        ]);

        $response = $this->actingAs($admin)
            ->get('/admin/whatsapp/conversations/'.$conversation->id);

        $response->assertOk();
        $response->assertSee('Hola vengo del cliente');
        $response->assertSee('Bienvenido, gracias por escribir');
    }

    public function test_send_message_creates_queued_outbound(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        $response = $this->actingAs($admin)->post(
            '/admin/whatsapp/conversations/'.$conversation->id.'/send',
            ['body' => 'Hola!'],
        );

        $response->assertRedirect();

        $this->assertDatabaseHas('whatsapp_messages', [
            'conversation_id' => $conversation->id,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => 'freeform',
            'body' => 'Hola!',
            'status' => WhatsAppMessage::STATUS_QUEUED,
        ]);

        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\V2\SendWhatsAppMessage::class);
    }

    public function test_send_message_requires_whatsapp_send_permission(): void
    {
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');

        $account = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        $this->actingAs($vendedor)->post(
            '/admin/whatsapp/conversations/'.$conversation->id.'/send',
            ['body' => 'Hola!'],
        )->assertForbidden();
    }

    public function test_assign_conversation_requires_assign_permission(): void
    {
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');

        $account = $this->makeAccount();
        $conversation = $this->makeConversation($account);
        $assignee = User::factory()->create(['is_active' => true]);

        $this->actingAs($vendedor)->post(
            '/admin/whatsapp/conversations/'.$conversation->id.'/assign',
            ['assigned_to' => $assignee->id],
        )->assertForbidden();
    }

    public function test_assign_conversation_enforces_data_scope_on_assignee(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeAccount();
        $conversation = $this->makeConversation($account);

        $outsideUser = User::factory()->create(['is_active' => true]);

        // Create a team with a supervisor (admin is NOT part of any team → admin
        // is global). Use a non-admin supervisor to exercise DataScope.
        $supervisor = User::factory()->create(['is_active' => true]);
        $supervisor->assignRole('supervisor');
        $supervisor->givePermissionTo('whatsapp.view');
        $supervisor->givePermissionTo('whatsapp.conversation.assign');
        $team = Team::create([
            'name' => 'Equipo Norte',
            'supervisor_id' => $supervisor->id,
            'is_active' => true,
        ]);
        $teamMember = User::factory()->create(['is_active' => true]);
        $team->members()->attach($teamMember->id);
        $outsider = User::factory()->create(['is_active' => true]);

        $this->actingAs($supervisor)->post(
            '/admin/whatsapp/conversations/'.$conversation->id.'/assign',
            ['assigned_to' => $teamMember->id],
        )->assertRedirect();

        $conversation->refresh();
        $this->assertSame($teamMember->id, (int) $conversation->assigned_to);

        // Out-of-scope assignee → 403.
        $this->actingAs($supervisor)->post(
            '/admin/whatsapp/conversations/'.$conversation->id.'/assign',
            ['assigned_to' => $outsider->id],
        )->assertForbidden();
    }

    public function test_close_conversation_updates_status_to_closed(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeAccount();
        $conversation = $this->makeConversation($account, [
            'status' => WhatsAppConversation::STATUS_OPEN,
        ]);

        $this->actingAs($admin)->post(
            '/admin/whatsapp/conversations/'.$conversation->id.'/close',
        )->assertRedirect();

        $conversation->refresh();
        $this->assertSame(WhatsAppConversation::STATUS_CLOSED, $conversation->status);
    }

    public function test_mark_opt_out_creates_consent_log_and_updates_conversation(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeAccount();
        $customer = Customer::factory()->forOwner($admin)->create();
        $contact = Contact::factory()->forCustomer($customer)->create();
        $conversation = $this->makeConversation($account, [
            'status' => WhatsAppConversation::STATUS_OPEN,
            'contact_id' => $contact->id,
        ]);

        $this->actingAs($admin)->post(
            '/admin/whatsapp/conversations/'.$conversation->id.'/opt-out',
        )->assertRedirect();

        $conversation->refresh();
        $this->assertSame('opted_out', $conversation->status);
        $this->assertNotNull($conversation->opt_out_at);

        $this->assertDatabaseHas('whatsapp_consent_log', [
            'conversation_id' => $conversation->id,
            'type' => WhatsAppConsentLog::TYPE_OPT_OUT,
        ]);
    }

    public function test_trigger_template_sync_returns_redirect(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeAccount();

        // The stub provider returns no templates so sync is a no-op.
        $response = $this->actingAs($admin)
            ->post('/admin/whatsapp/accounts/'.$account->id.'/sync-templates');

        $response->assertRedirect();
    }

    public function test_trigger_template_sync_requires_template_manage_permission(): void
    {
        $vendedor = User::factory()->create(['is_active' => true]);
        $vendedor->assignRole('vendedor');

        $account = $this->makeAccount();

        $this->actingAs($vendedor)
            ->post('/admin/whatsapp/accounts/'.$account->id.'/sync-templates')
            ->assertForbidden();
    }

    public function test_templates_index_filters_by_account_status_category(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $accountA = $this->makeAccount();
        $accountB = $this->makeAccount();

        WhatsAppTemplate::create([
            'account_id' => $accountA->id,
            'name' => 'bienvenida_a',
            'language' => 'es_PE',
            'status' => WhatsAppTemplate::STATUS_APPROVED,
            'category' => 'MARKETING',
            'body' => 'Hola',
        ]);
        WhatsAppTemplate::create([
            'account_id' => $accountB->id,
            'name' => 'bienvenida_b',
            'language' => 'es_PE',
            'status' => WhatsAppTemplate::STATUS_APPROVED,
            'category' => 'UTILITY',
            'body' => 'Hola',
        ]);

        $response = $this->actingAs($admin)
            ->get('/admin/whatsapp/templates?account_id='.$accountA->id);

        $response->assertOk();
        $response->assertSee('bienvenida_a');
        $response->assertDontSee('bienvenida_b');

        $response = $this->actingAs($admin)
            ->get('/admin/whatsapp/templates?status=approved&category=UTILITY');

        $response->assertOk();
        $response->assertSee('bienvenida_b');
        $response->assertDontSee('bienvenida_a');
    }

    public function test_show_template_renders_body_and_messages(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $account = $this->makeAccount();
        $template = WhatsAppTemplate::create([
            'account_id' => $account->id,
            'name' => 'bienvenida',
            'language' => 'es_PE',
            'status' => WhatsAppTemplate::STATUS_APPROVED,
            'category' => 'MARKETING',
            'body' => 'Hola cliente, bienvenido!',
        ]);

        $conversation = $this->makeConversation($account);
        WhatsAppMessage::create([
            'conversation_id' => $conversation->id,
            'template_id' => $template->id,
            'direction' => WhatsAppMessage::DIRECTION_OUTBOUND,
            'type' => 'template',
            'body' => 'Hola cliente, bienvenido!',
            'status' => WhatsAppMessage::STATUS_SENT,
            'provider_message_id' => 'tmpl-1',
        ]);

        $response = $this->actingAs($admin)
            ->get('/admin/whatsapp/templates/'.$template->id);

        $response->assertOk();
        $response->assertSee('Hola cliente, bienvenido!');
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
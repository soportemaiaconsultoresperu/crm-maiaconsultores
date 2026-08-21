<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\Email;

use App\Models\Email\EmailTemplate;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * B13 Pasada B — Admin EmailController HTTP + permission gate tests.
 */
class AdminEmailControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        // Re-run the Email provider's permission registration so the
        // admin / supervisor role pick up the B13 grants.
        app()->register(\App\Providers\EmailServiceProvider::class, force: true);
    }

    public function test_index_is_gated_by_email_view_permission(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('vendedor');

        // vendedor lacks email.view by default.
        $this->actingAs($user)->get('/admin/email/templates')->assertForbidden();

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/admin/email/templates')->assertOk();
    }

    public function test_create_form_is_gated_by_email_template_manage(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole('vendedor');

        $this->actingAs($user)
            ->get('/admin/email/templates/create')
            ->assertForbidden();
    }

    public function test_store_creates_an_email_template_with_initial_version_snapshot(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)
            ->post('/admin/email/templates', [
                'name' => 'Bienvenida',
                'slug' => 'bienvenida',
                'subject' => 'Hola',
                'body_html' => '<p>Hola {{ customer_name }}</p>',
                'body_text' => 'Hola {{ customer_name }}',
                'variables_json' => ['customer_name'],
                'is_active' => true,
            ]);

        $response->assertRedirect();

        $template = EmailTemplate::query()->where('slug', 'bienvenida')->firstOrFail();
        $this->assertSame(1, (int) $template->version);
        $this->assertNotNull($template->created_by);

        $this->assertDatabaseHas('email_template_versions', [
            'template_id' => $template->id,
            'version' => 1,
        ]);
    }

    public function test_destroy_soft_deletes_template(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $template = EmailTemplate::create([
            'name' => 'X',
            'slug' => 'x',
            'subject' => 's',
            'body_html' => '<p>x</p>',
            'body_text' => 'x',
            'variables_json' => [],
            'is_active' => true,
            'version' => 1,
            'created_by' => null,
        ]);

        $this->actingAs($admin)
            ->delete('/admin/email/templates/'.$template->id)
            ->assertRedirect('/admin/email/templates');

        $this->assertNull(EmailTemplate::query()->find($template->id));
        $this->assertNotNull(EmailTemplate::query()->onlyTrashed()->find($template->id));
    }

    public function test_accounts_lists_only_email_providers(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        // Seed one of each provider — only smtp/gmail/outlook should appear.
        \App\Models\IntegrationAccount::create([
            'provider' => 'smtp',
            'label' => 'SMTP',
            'is_active' => true,
            'test_mode' => true,
        ]);
        \App\Models\IntegrationAccount::create([
            'provider' => 'whatsapp',
            'label' => 'WhatsApp',
            'is_active' => true,
            'test_mode' => true,
        ]);

        $response = $this->actingAs($admin)->get('/admin/email/accounts');
        $response->assertOk();
        $response->assertSee('SMTP');
        $response->assertDontSee('WhatsApp');
    }
}

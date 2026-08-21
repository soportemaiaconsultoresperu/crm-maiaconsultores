<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('Iniciar sesión');
        $response->assertSee('Correo electrónico');
        $response->assertSee('Contraseña');
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_users_can_authenticate_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'ventas@maia.test',
            'password' => 'secreta-123',
            'is_active' => true,
        ]);

        $response = $this->post(route('login.store'), [
            'email' => 'ventas@maia.test',
            'password' => 'secreta-123',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertNotNull($user->last_login_at, 'last_login_at must be updated on login');
    }

    public function test_users_cannot_authenticate_with_an_invalid_password(): void
    {
        $user = User::factory()->create([
            'email' => 'ventas@maia.test',
            'password' => 'secreta-123',
            'is_active' => true,
        ]);

        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => 'ventas@maia.test',
            'password' => 'contramala',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
        $response->assertRedirect(route('login'));
    }

    public function test_inactive_users_cannot_log_in_with_correct_password(): void
    {
        // RF-USR-002: a deactivated user must not start a session even
        // with valid credentials, and the error must be generic.
        User::factory()->create([
            'email' => 'baja@maia.test',
            'password' => 'secreta-123',
            'is_active' => false,
        ]);

        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => 'baja@maia.test',
            'password' => 'secreta-123',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_authenticated_inactive_users_are_rejected_by_middleware(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user);

        // Deactivated after logging in (RF-USR-002, second gate).
        $user->update(['is_active' => false]);

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_dashboard_renders_for_active_authenticated_users(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Próximas reuniones')
            ->assertSee('Rendimiento por vendedor');
    }

    public function test_users_can_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->post(route('logout'));

        $this->assertGuest();
        $response->assertRedirect(route('login'));
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        User::factory()->create([
            'email' => 'ventas@maia.test',
            'password' => 'secreta-123',
            'is_active' => true,
        ]);

        foreach (range(1, 5) as $attempt) {
            $this->from(route('login'))->post(route('login.store'), [
                'email' => 'ventas@maia.test',
                'password' => 'contramala',
            ])->assertRedirect(route('login'));
        }

        // Sixth attempt is locked out, even with the CORRECT password.
        $response = $this->from(route('login'))->post(route('login.store'), [
            'email' => 'ventas@maia.test',
            'password' => 'secreta-123',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');

        $errors = session('errors')->get('email');
        $this->assertStringStartsWith('Demasiados intentos', implode(' ', (array) $errors));
        $this->assertGuest();
    }
}

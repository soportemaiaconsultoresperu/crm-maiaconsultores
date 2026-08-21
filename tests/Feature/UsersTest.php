<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_admin_user_exists_with_admin_role(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', env('ADMIN_EMAIL'))->first();

        $this->assertNotNull($admin, 'Admin user from .env ADMIN_EMAIL must exist');
        $this->assertTrue($admin->hasRole('admin'));
    }

    public function test_is_active_is_cast_to_boolean(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->assertTrue($user->is_active);

        $user->update(['is_active' => false]);
        $this->assertFalse($user->refresh()->is_active);
    }

    public function test_last_login_at_is_cast_to_datetime(): void
    {
        $user = User::factory()->create(['last_login_at' => '2026-08-17 10:00:00']);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $user->last_login_at);
    }
}

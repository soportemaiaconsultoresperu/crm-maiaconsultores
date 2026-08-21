<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the bootstrap admin user from environment variables.
 * Credentials live ONLY in .env (never committed); .env.example ships
 * empty placeholders.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $name = env('ADMIN_NAME');
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (blank($email) || blank($password)) {
            throw new \RuntimeException(
                'ADMIN_EMAIL and ADMIN_PASSWORD must be defined in .env to seed the admin user.'
            );
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name ?: 'CRM Admin',
                'password' => Hash::make($password),
                'is_active' => true,
            ]
        );

        $user->assignRole('admin');
    }
}

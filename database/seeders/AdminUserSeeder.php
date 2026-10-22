<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the bootstrap admin user from environment variables.
 * Credentials live in .env (never committed); .env.example ships empty
 * placeholders.
 *
 * Local development only: when APP_ENV=local and the variables are empty,
 * the fixed LOCAL_* development credentials are used so a fresh checkout can
 * log in. Every other environment still fails fast without .env values.
 */
class AdminUserSeeder extends Seeder
{
    public const LOCAL_EMAIL = 'admin@maia.local';

    public const LOCAL_PASSWORD = 'Maia2026!';

    public function run(): void
    {
        $name = env('ADMIN_NAME');
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (app()->environment('local') && blank($email) && blank($password)) {
            $email = self::LOCAL_EMAIL;
            $password = self::LOCAL_PASSWORD;
        }

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

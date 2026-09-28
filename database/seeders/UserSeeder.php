<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One administrator plus a few cashiers. Passwords are all "password".
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            [
                'name' => 'Store Administrator',
                'email' => 'admin@pos.test',
                'role' => UserRole::Admin,
                'phone' => '+1 555 0101',
                'address' => '12 Market Street, Downtown',
                'is_active' => true,
            ],
            [
                'name' => 'Alice Cashier',
                'email' => 'alice@pos.test',
                'role' => UserRole::Staff,
                'phone' => '+1 555 0102',
                'is_active' => true,
            ],
            [
                'name' => 'Bruno Cashier',
                'email' => 'bruno@pos.test',
                'role' => UserRole::Staff,
                'phone' => '+1 555 0103',
                'is_active' => true,
            ],
            [
                'name' => 'Carla (suspended)',
                'email' => 'carla@pos.test',
                'role' => UserRole::Staff,
                'is_active' => false,
            ],
        ];

        foreach ($users as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                $user + [
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'last_login_at' => $user['is_active'] ? now()->subHours(random_int(1, 72)) : null,
                ],
            );
        }
    }
}

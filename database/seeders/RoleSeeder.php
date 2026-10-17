<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One account per role so the permission split can be exercised by hand.
 *
 * These are development accounts. Nothing in the seeders creates production
 * users, and the passwords live only in the local .env.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [Role::Buyer, 'buyer@opokuauctions.test', 'Kwame Buyer'],
            [Role::Ops, 'ops@opokuauctions.test', 'Ama Operations'],
            [Role::Owner, 'owner@opokuauctions.test', 'Yaw Owner'],
        ];

        foreach ($accounts as [$role, $email, $name]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'role' => $role,
                    'password' => 'OpokuAdmin!2026',
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}

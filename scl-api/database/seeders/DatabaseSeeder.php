<?php

namespace Database\Seeders;

use App\Models\CompanySettings;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        CompanySettings::query()->firstOrCreate([], [
            'name'      => 'SCL',
            'email'     => 'hello@scl.test',
            'timezone'  => 'Asia/Kolkata',
            'is_active' => true,
        ]);

        User::query()->firstOrCreate(
            ['email' => 'owner@crm.local'],
            [
                'name'              => 'Superadmin',
                'password'          => 'password',
                'role'              => 'superadmin',
                'branch_id'         => null,
                'manager_id'        => null,
                'is_active'         => true,
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('Seeded company SCL and superadmin owner@crm.local / password.');
        $this->command->info('Log in, then create a branch. Each branch gets a default sales pipeline.');
    }
}

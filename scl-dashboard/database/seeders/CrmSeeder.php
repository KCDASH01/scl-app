<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Legacy SaaS demo seeder. Disabled for the standalone single-company CRM.
 * Use `php artisan crm:setup` in scl-api instead.
 */
class CrmSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->warn('CrmSeeder no longer creates sample organizations or subscriptions.');
        $this->command->info('Run `php artisan crm:setup` from scl-api to create the client company.');
    }
}

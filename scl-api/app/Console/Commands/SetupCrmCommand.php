<?php

namespace App\Console\Commands;

use App\Models\CompanySettings;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SetupCrmCommand extends Command
{
    protected $signature = 'crm:setup
        {--name= : Company name}
        {--email= : Company email}
        {--owner-name= : First superadmin full name}
        {--owner-email= : First superadmin email}
        {--password= : First superadmin password (prefer interactive prompt)}
        {--timezone=Asia/Kolkata : Company timezone}';

    protected $description = 'Create company settings and the first superadmin. Create branches from the CRM app next.';

    public function handle(): int
    {
        if (User::query()->exists()) {
            $this->error('Users already exist. Add further accounts from Team in the CRM app.');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Company name');
        $email = $this->option('email') ?: $this->ask('Company email');
        $timezone = $this->option('timezone') ?: 'Asia/Kolkata';
        $ownerName = $this->option('owner-name') ?: $this->ask('Superadmin full name');
        $ownerEmail = $this->option('owner-email') ?: $this->ask('Superadmin email');
        $password = $this->option('password') ?: $this->secret('Superadmin password (min 8 characters)');

        $validator = Validator::make([
            'name'        => $name,
            'email'       => $email,
            'timezone'    => $timezone,
            'owner_name'  => $ownerName,
            'owner_email' => $ownerEmail,
            'password'    => $password,
        ], [
            'name'        => ['required', 'string', 'max:191'],
            'email'       => ['required', 'email', 'max:191'],
            'timezone'    => ['required', 'string', 'max:64'],
            'owner_name'  => ['required', 'string', 'max:191'],
            'owner_email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'password'    => ['required', 'string', 'min:8'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $email, $timezone, $ownerName, $ownerEmail, $password) {
            CompanySettings::query()->delete();
            CompanySettings::create([
                'name'      => $name,
                'email'     => $email,
                'timezone'  => $timezone,
                'is_active' => true,
            ]);

            User::create([
                'name'              => $ownerName,
                'email'             => $ownerEmail,
                'password'          => $password,
                'role'              => 'superadmin',
                'branch_id'         => null,
                'is_active'         => true,
                'email_verified_at' => now(),
            ]);
        });

        $this->info('Company and superadmin created. Log in, create a branch, then add a manager and employees.');

        return self::SUCCESS;
    }
}

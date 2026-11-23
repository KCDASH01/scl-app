<?php

namespace Tests;

use App\Models\Branch;
use App\Models\CompanySettings;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function migrateFreshUsing()
    {
        $seeder = $this->seeder();
        $path   = realpath(__DIR__ . '/../../scl-dashboard/database/migrations');

        $params = array_merge(
            [
                '--drop-views' => $this->shouldDropViews(),
                '--drop-types' => $this->shouldDropTypes(),
                '--path'       => $path,
                '--realpath'   => true,
            ],
            $seeder ? ['--seeder' => $seeder] : ['--seed' => $this->shouldSeed()]
        );

        if (config('database.default') === 'sqlite') {
            $skip = [
                'add_whatsapp_to_activities_type_enum',
                'add_enterprise_to_subscriptions_plan_enum',
                'add_lead_source_enum_values',
                'add_lead_status_enum_values',
                'rename_lead_status_qualified_unqualified',
                'expand_lead_timeline_action_enum',
                'add_types_to_leads_table',
                'add_others_to_lead_types_enum',
                'set_deal_currency_default_inr',
            ];

            $params['--path'] = collect(glob($path . '/*.php'))
                ->reject(fn (string $file) => collect($skip)->contains(
                    fn (string $needle) => str_contains($file, $needle)
                ))
                ->values()
                ->all();
        }

        return $params;
    }

    protected function createCompany(array $company = [], array $owner = []): User
    {
        CompanySettings::query()->delete();
        CompanySettings::create(array_merge([
            'name'      => 'Acme Ltd',
            'email'     => 'ops@acme.test',
            'timezone'  => 'Asia/Kolkata',
            'is_active' => true,
        ], $company));

        return $this->createUser(array_merge([
            'name'              => 'Owner User',
            'email'             => 'owner@acme.test',
            'password'          => 'password',
            'role'              => 'superadmin',
            'branch_id'         => null,
            'is_active'         => true,
            'email_verified_at' => now(),
        ], $owner));
    }

    protected function createBranch(array $attrs = []): Branch
    {
        static $n = 0;
        $n++;

        return Branch::create(array_merge([
            'name'      => 'Branch '.$n,
            'code'      => 'BR'.$n.substr(uniqid(), -4),
            'is_active' => true,
        ], $attrs));
    }

    protected function createUser(array $attrs = []): User
    {
        $user = new User(array_merge([
            'name'              => 'User',
            'email'             => 'user@acme.test',
            'password'          => 'password',
            'role'              => 'employee',
            'is_active'         => true,
            'email_verified_at' => now(),
        ], $attrs));

        if (Schema::hasColumn('users', 'organization_id')) {
            $orgId = DB::table('organizations')->orderBy('id')->value('id');
            if (! $orgId) {
                $orgId = DB::table('organizations')->insertGetId([
                    'name'       => 'Acme Ltd',
                    'slug'       => 'acme-ltd',
                    'email'      => 'ops@acme.test',
                    'timezone'   => 'Asia/Kolkata',
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $user->setAttribute('organization_id', $orgId);
        }

        $user->save();

        return $user;
    }

    protected function createCourse(array $attrs): \App\Models\Course
    {
        return \App\Models\Course::create(array_merge([
            'name'      => 'BCA',
            'code'      => 'BCA'.substr(uniqid(), -4),
            'is_active' => true,
        ], $attrs));
    }

    protected function createBatch(array $attrs): \App\Models\Batch
    {
        return \App\Models\Batch::create(array_merge([
            'name'       => 'Morning',
            'code'       => 'M'.substr(uniqid(), -4),
            'start_date' => now()->toDateString(),
            'status'     => 'upcoming',
        ], $attrs));
    }

    protected function createLead(array $attrs): Lead
    {
        $lead = new Lead($attrs);

        if (Schema::hasColumn('leads', 'organization_id') && ! $lead->getAttribute('organization_id')) {
            $orgId = DB::table('organizations')->orderBy('id')->value('id');
            if (! $orgId) {
                $orgId = DB::table('organizations')->insertGetId([
                    'name'       => 'Acme Ltd',
                    'slug'       => 'acme-ltd',
                    'email'      => 'ops@acme.test',
                    'timezone'   => 'Asia/Kolkata',
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $lead->setAttribute('organization_id', $orgId);
        }

        $lead->save();

        return $lead;
    }

    protected function createDeal(array $attrs): \App\Models\Deal
    {
        $deal = new \App\Models\Deal($attrs);

        if (Schema::hasColumn('deals', 'organization_id') && ! $deal->getAttribute('organization_id')) {
            $orgId = DB::table('organizations')->orderBy('id')->value('id');
            if (! $orgId) {
                $orgId = DB::table('organizations')->insertGetId([
                    'name'       => 'Acme Ltd',
                    'slug'       => 'acme-ltd',
                    'email'      => 'ops@acme.test',
                    'timezone'   => 'Asia/Kolkata',
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $deal->setAttribute('organization_id', $orgId);
        }

        $deal->save();

        return $deal;
    }
}

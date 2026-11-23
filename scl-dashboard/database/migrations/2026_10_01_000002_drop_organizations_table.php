<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single-tenant CRM: company profile lives in company_settings.
 * organization_id columns and the organizations table are removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('company_settings')) {
            Schema::create('company_settings', function (Blueprint $table) {
                $table->id();
                $table->string('name')->default('Company');
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                $table->string('website')->nullable();
                $table->string('logo')->nullable();
                $table->string('address')->nullable();
                $table->string('city')->nullable();
                $table->string('country')->nullable();
                $table->string('timezone')->default('Asia/Kolkata');
                $table->json('settings')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('organizations') && DB::table('company_settings')->count() === 0) {
            $org = DB::table('organizations')->orderBy('id')->first();
            if ($org) {
                DB::table('company_settings')->insert([
                    'name'       => $org->name ?? 'Company',
                    'email'      => $org->email,
                    'phone'      => $org->phone,
                    'website'    => $org->website,
                    'logo'       => $org->logo,
                    'address'    => $org->address,
                    'city'       => $org->city,
                    'country'    => $org->country,
                    'timezone'   => $org->timezone ?: 'Asia/Kolkata',
                    'settings'   => $org->settings,
                    'is_active'  => (bool) ($org->is_active ?? true),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach ([
            'users',
            'departments',
            'pipelines',
            'stages',
            'leads',
            'deals',
            'activities',
            'notes',
            'lead_timeline',
            'notifications',
            'email_templates',
            'sales_targets',
            'deal_payments',
            'proposals',
        ] as $table) {
            $this->dropOrganizationId($table);
        }

        Schema::dropIfExists('organizations');
    }

    public function down(): void
    {
        if (! Schema::hasTable('organizations')) {
            Schema::create('organizations', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('email')->nullable();
                $table->string('timezone')->default('UTC');
                $table->json('settings')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        Schema::dropIfExists('company_settings');
    }

    private function dropOrganizationId(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'organization_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver !== 'sqlite') {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (in_array('organization_id', $fk['columns'] ?? [], true)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($fk) {
                        $blueprint->dropForeign($fk['name']);
                    });
                }
            }
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (! empty($index['primary'])) {
                continue;
            }

            $cols = $index['columns'] ?? [];
            if (! in_array('organization_id', $cols, true)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($index) {
                if (! empty($index['unique'])) {
                    $blueprint->dropUnique($index['name']);
                } else {
                    $blueprint->dropIndex($index['name']);
                }
            });

            $remaining = array_values(array_diff($cols, ['organization_id']));

            if ($remaining === []) {
                continue;
            }

            if (! empty($index['unique'])) {
                Schema::table($table, function (Blueprint $blueprint) use ($remaining) {
                    $blueprint->unique($remaining);
                });
            } elseif ($remaining === ['phone_normalized']) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->index('phone_normalized');
                });
            }
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropColumn('organization_id');
        });
    }
};

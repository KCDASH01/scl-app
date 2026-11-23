<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes SaaS billing tables and Lead Scraping SSO columns.
 *
 * Rollback recreates empty table shells only. Deleted subscription/payment
 * rows cannot be recovered without a backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('subscription_payments');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');

        if (Schema::hasTable('users')) {
            $ssoColumns = ['external_id', 'is_sso_user', 'sso_provider'];

            foreach (Schema::getIndexes('users') as $index) {
                if (! empty($index['primary'])) {
                    continue;
                }

                $coversSso = array_intersect($index['columns'] ?? [], $ssoColumns) !== [];

                if (! $coversSso) {
                    continue;
                }

                Schema::table('users', function (Blueprint $table) use ($index) {
                    if (! empty($index['unique'])) {
                        $table->dropUnique($index['name']);
                    } else {
                        $table->dropIndex($index['name']);
                    }
                });
            }

            Schema::table('users', function (Blueprint $table) use ($ssoColumns) {
                $drop = [];
                foreach ($ssoColumns as $column) {
                    if (Schema::hasColumn('users', $column)) {
                        $drop[] = $column;
                    }
                }
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'external_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('external_id')->nullable()->unique();
                $table->boolean('is_sso_user')->default(false);
                $table->string('sso_provider')->nullable();
            });
        }

        if (! Schema::hasTable('plans')) {
            Schema::create('plans', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subscriptions')) {
            Schema::create('subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('plan')->default('free');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('subscription_payments')) {
            Schema::create('subscription_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
            });
        }
    }
};

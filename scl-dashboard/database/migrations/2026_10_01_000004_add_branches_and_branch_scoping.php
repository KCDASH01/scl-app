<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single-company branch hierarchy: Superadmin → Manager → Employee.
 * Superadmin has no branch. Managers and employees belong to one branch.
 * Employees report to one manager in the same branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code');
                $table->string('address')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique('code');
                $table->index('is_active');
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (! Schema::hasColumn('users', 'branch_id')) {
                    $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->restrictOnDelete();
                    $table->index(['branch_id', 'role']);
                }
                if (! Schema::hasColumn('users', 'manager_id')) {
                    $table->foreignId('manager_id')->nullable()->after('branch_id')->constrained('users')->nullOnDelete();
                    $table->index('manager_id');
                }
            });
        }

        foreach ([
            'departments',
            'pipelines',
            'leads',
            'deals',
            'activities',
            'sales_targets',
            'proposals',
            'deal_payments',
        ] as $tableName) {
            $this->ensureBranchColumn($tableName);
        }

        if (Schema::hasTable('leads') && Schema::hasColumn('leads', 'branch_id')) {
            try {
                Schema::table('leads', function (Blueprint $table) {
                    $table->dropIndex('leads_org_phone_normalized_index');
                });
            } catch (\Throwable) {
                // Index may already have been renamed or dropped.
            }

            try {
                Schema::table('leads', function (Blueprint $table) {
                    $table->unique(['branch_id', 'phone_normalized'], 'leads_branch_phone_normalized_unique');
                });
            } catch (\Throwable) {
                // Unique index may already exist.
            }
        }
    }

    private function ensureBranchColumn(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (! Schema::hasColumn($tableName, 'branch_id')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->index('branch_id');
            });
        }

        if (in_array($tableName, ['pipelines', 'departments'], true)) {
            $this->removeOrphanBranchRows($tableName);
        }

        $this->addBranchForeignKey($tableName);
    }

    private function removeOrphanBranchRows(string $tableName): void
    {
        $validIds = Schema::hasTable('branches')
            ? DB::table('branches')->pluck('id')->all()
            : [];

        $query = DB::table($tableName)->where(function ($q) use ($validIds) {
            $q->whereNull('branch_id')->orWhere('branch_id', 0);
            if ($validIds !== []) {
                $q->orWhereNotIn('branch_id', $validIds);
            }
        });

        if ($tableName === 'pipelines') {
            $ids = $query->pluck('id');
            if ($ids->isNotEmpty()) {
                if (Schema::hasTable('stages')) {
                    DB::table('stages')->whereIn('pipeline_id', $ids)->delete();
                }
                DB::table('pipelines')->whereIn('id', $ids)->delete();
            }

            return;
        }

        $query->delete();
    }

    private function addBranchForeignKey(string $tableName): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            $fk = $tableName.'_branch_id_foreign';
            $exists = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                [$tableName, $fk]
            );

            if ($exists !== []) {
                return;
            }
        }

        try {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            });
        } catch (\Throwable) {
            // Foreign key already exists, or the driver does not support this alter.
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leads') && Schema::hasColumn('leads', 'branch_id')) {
            try {
                Schema::table('leads', function (Blueprint $table) {
                    $table->dropUnique('leads_branch_phone_normalized_unique');
                });
            } catch (\Throwable) {
                //
            }
        }

        foreach ([
            'deal_payments',
            'proposals',
            'sales_targets',
            'activities',
            'deals',
            'leads',
            'pipelines',
            'departments',
        ] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'branch_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                try {
                    $table->dropForeign(['branch_id']);
                } catch (\Throwable) {
                    //
                }
                $table->dropColumn('branch_id');
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'manager_id')) {
                    try {
                        $table->dropForeign(['manager_id']);
                    } catch (\Throwable) {
                    }
                    $table->dropColumn('manager_id');
                }
                if (Schema::hasColumn('users', 'branch_id')) {
                    try {
                        $table->dropForeign(['branch_id']);
                    } catch (\Throwable) {
                    }
                    $table->dropColumn('branch_id');
                }
            });
        }

        Schema::dropIfExists('branches');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_roles')) {
            Schema::create('staff_roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('access', 32);
                $table->timestamps();
                $table->softDeletes();
                $table->unique('name');
            });
        }

        $now = now();
        $employeeId = DB::table('staff_roles')->where('name', 'Employee')->value('id');
        if (! $employeeId) {
            $employeeId = DB::table('staff_roles')->insertGetId([
                'name'       => 'Employee',
                'access'     => 'employee',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $managerId = DB::table('staff_roles')->where('name', 'Manager')->value('id');
        if (! $managerId) {
            $managerId = DB::table('staff_roles')->insertGetId([
                'name'       => 'Manager',
                'access'     => 'manager',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! Schema::hasColumn('users', 'staff_role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('staff_role_id')->nullable()->constrained('staff_roles')->nullOnDelete();
            });
        }

        DB::table('users')->where('role', 'employee')->whereNull('staff_role_id')->update(['staff_role_id' => $employeeId]);
        DB::table('users')->whereIn('role', ['manager', 'admin'])->whereNull('staff_role_id')->update(['staff_role_id' => $managerId]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'staff_role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('staff_role_id');
            });
        }

        Schema::dropIfExists('staff_roles');
    }
};

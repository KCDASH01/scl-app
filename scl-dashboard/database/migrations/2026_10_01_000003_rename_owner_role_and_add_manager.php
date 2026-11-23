<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role', 32)->default('employee')->change();
            });
            DB::table('users')->where('role', 'owner')->update(['role' => 'superadmin']);

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'superadmin', 'admin', 'manager', 'employee') NOT NULL DEFAULT 'employee'");
        }

        DB::table('users')->where('role', 'owner')->update(['role' => 'superadmin']);

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('superadmin', 'admin', 'manager', 'employee') NOT NULL DEFAULT 'employee'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        DB::table('users')->where('role', 'manager')->update(['role' => 'admin']);
        DB::table('users')->where('role', 'superadmin')->update(['role' => 'owner']);

        if ($driver === 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role', 32)->default('employee')->change();
            });

            return;
        }

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('owner', 'admin', 'employee') NOT NULL DEFAULT 'employee'");
        }
    }
};

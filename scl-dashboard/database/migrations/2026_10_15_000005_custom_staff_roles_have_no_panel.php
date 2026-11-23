<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $customIds = DB::table('staff_roles')
            ->whereNotIn('name', ['Employee', 'Manager'])
            ->pluck('id');

        if ($customIds->isEmpty()) {
            return;
        }

        DB::table('users')
            ->whereIn('staff_role_id', $customIds)
            ->where('role', 'manager')
            ->update(['role' => 'employee']);

        DB::table('staff_roles')
            ->whereIn('id', $customIds)
            ->update(['access' => 'none']);
    }

    public function down(): void
    {
        DB::table('staff_roles')
            ->where('access', 'none')
            ->update(['access' => 'employee']);
    }
};

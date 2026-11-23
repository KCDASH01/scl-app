<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->boolean('hostel_stay')->default(false)->after('status');
            $table->decimal('hostel_amount', 12, 2)->nullable()->after('hostel_stay');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropColumn(['hostel_stay', 'hostel_amount']);
        });
    }
};

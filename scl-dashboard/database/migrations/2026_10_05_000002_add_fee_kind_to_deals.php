<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('fee_kind', 16)->default('course')->after('title');
            $table->index(['fee_kind', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropIndex(['fee_kind', 'lead_id']);
            $table->dropColumn('fee_kind');
        });
    }
};

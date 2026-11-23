<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student-oriented lead fields: state, pin, and a course from the branch catalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'state')) {
                $table->string('state')->nullable()->after('city');
            }
            if (! Schema::hasColumn('leads', 'pin')) {
                $table->string('pin', 16)->nullable()->after('country');
            }
            if (! Schema::hasColumn('leads', 'course_id')) {
                $table->foreignId('course_id')->nullable()->after('branch_id')
                    ->constrained('courses')->restrictOnDelete();
                $table->index('course_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'course_id')) {
                $table->dropConstrainedForeignId('course_id');
            }
            foreach (['state', 'pin'] as $column) {
                if (Schema::hasColumn('leads', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

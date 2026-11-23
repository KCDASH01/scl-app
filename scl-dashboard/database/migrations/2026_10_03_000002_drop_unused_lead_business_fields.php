<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove leftover B2B lead columns after switching the form to student details.
 */
return new class extends Migration
{
    public function up(): void
    {
        $drop = [];
        foreach (['company', 'job_title', 'website', 'types', 'industry', 'notes', 'score'] as $column) {
            if (Schema::hasColumn('leads', $column)) {
                $drop[] = $column;
            }
        }

        if ($drop === []) {
            return;
        }

        Schema::table('leads', function (Blueprint $table) use ($drop) {
            $table->dropColumn($drop);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'company')) {
                $table->string('company')->nullable();
            }
            if (! Schema::hasColumn('leads', 'job_title')) {
                $table->string('job_title')->nullable();
            }
            if (! Schema::hasColumn('leads', 'website')) {
                $table->string('website')->nullable();
            }
            if (! Schema::hasColumn('leads', 'types')) {
                $table->string('types', 64)->nullable();
            }
            if (! Schema::hasColumn('leads', 'industry')) {
                $table->string('industry')->nullable();
            }
            if (! Schema::hasColumn('leads', 'notes')) {
                $table->text('notes')->nullable();
            }
            if (! Schema::hasColumn('leads', 'score')) {
                $table->unsignedTinyInteger('score')->default(0);
            }
        });
    }
};

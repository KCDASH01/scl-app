<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_records', 'subject_id')) {
                $table->foreignId('subject_id')->nullable()->after('batch_id')->constrained('subjects')->restrictOnDelete();
            }
            if (! Schema::hasColumn('attendance_records', 'subject_key')) {
                $table->unsignedBigInteger('subject_key')->default(0)->after('subject_id');
            }
        });

        $indexes = collect(Schema::getIndexes('attendance_records'))->pluck('name');

        Schema::table('attendance_records', function (Blueprint $table) use ($indexes) {
            if (! $indexes->contains('attendance_records_admission_id_index')) {
                $table->index('admission_id', 'attendance_records_admission_id_index');
            }
            if ($indexes->contains('attendance_records_admission_id_attendance_date_unique')) {
                $table->dropUnique(['admission_id', 'attendance_date']);
            }
            if (! $indexes->contains('attendance_day_subject_unique')) {
                $table->unique(['admission_id', 'attendance_date', 'subject_key'], 'attendance_day_subject_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropUnique('attendance_day_subject_unique');
            $table->unique(['admission_id', 'attendance_date']);
            $table->dropConstrainedForeignId('subject_id');
            $table->dropColumn('subject_key');
        });
    }
};

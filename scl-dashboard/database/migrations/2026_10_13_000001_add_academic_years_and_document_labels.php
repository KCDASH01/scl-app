<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 16)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('admissions', function (Blueprint $table) {
            $table->foreignId('academic_year_id')->nullable()->after('batch_id')->constrained('academic_years')->nullOnDelete();
            $table->string('student_year', 8)->nullable()->after('academic_year_id');
        });

        Schema::table('admission_documents', function (Blueprint $table) {
            $table->string('doc_label', 100)->nullable()->after('doc_type');
        });
    }

    public function down(): void
    {
        Schema::table('admission_documents', function (Blueprint $table) {
            $table->dropColumn('doc_label');
        });

        Schema::table('admissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_year_id');
            $table->dropColumn('student_year');
        });

        Schema::dropIfExists('academic_years');
    }
};

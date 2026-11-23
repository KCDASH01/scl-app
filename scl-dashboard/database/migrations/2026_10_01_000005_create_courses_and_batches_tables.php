<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * College CRM: Branch → Course → Batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->text('description')->nullable();
            $table->unsignedInteger('duration_value')->nullable();
            $table->string('duration_unit', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['branch_id', 'code']);
            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->string('name');
            $table->string('code');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->string('schedule')->nullable();
            $table->string('status', 32)->default('upcoming');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['course_id', 'code']);
            $table->index(['branch_id', 'status']);
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
        Schema::dropIfExists('courses');
    }
};

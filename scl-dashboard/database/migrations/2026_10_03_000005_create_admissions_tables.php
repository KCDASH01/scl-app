<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->unique()->constrained('leads')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->restrictOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('batches')->restrictOnDelete();
            $table->date('admission_date')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('enrollment_id', 64)->unique();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'course_id']);
            $table->index(['course_id', 'batch_id']);
        });

        Schema::create('admission_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained('admissions')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('path');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->string('doc_type', 32)->default('other');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['admission_id', 'doc_type']);
        });

        $this->backfillConvertedLeads();
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_documents');
        Schema::dropIfExists('admissions');
    }

    private function backfillConvertedLeads(): void
    {
        if (! Schema::hasTable('leads') || ! Schema::hasColumn('leads', 'status')) {
            return;
        }

        $now = now();
        $year = (int) $now->year;
        $next = [];

        $converted = DB::table('leads')
            ->where('status', 'converted')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($converted as $lead) {
            if (! $lead->branch_id) {
                continue;
            }

            if (DB::table('admissions')->where('lead_id', $lead->id)->exists()) {
                continue;
            }

            $branch = DB::table('branches')->where('id', $lead->branch_id)->first();
            $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($branch->code ?? '')) ?: 'BR');
            $key = $lead->branch_id.'|'.$year;
            $next[$key] = ($next[$key] ?? 0) + 1;
            $enrollmentId = sprintf('%s-%d-%04d', $code, $year, $next[$key]);

            $courseId = $lead->course_id ?? null;
            if ($courseId) {
                $ok = DB::table('courses')
                    ->where('id', $courseId)
                    ->where('branch_id', $lead->branch_id)
                    ->whereNull('deleted_at')
                    ->exists();
                if (! $ok) {
                    $courseId = null;
                }
            }

            DB::table('admissions')->insert([
                'lead_id'         => $lead->id,
                'branch_id'       => $lead->branch_id,
                'course_id'       => $courseId,
                'batch_id'        => null,
                'admission_date'  => $now->toDateString(),
                'status'          => 'pending',
                'enrollment_id'   => $enrollmentId,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('course_period_price_backups')) {
            Schema::create('course_period_price_backups', function (Blueprint $table) {
                $table->unsignedBigInteger('course_id')->primary();
                $table->unsignedInteger('duration_value');
                $table->string('duration_unit', 16);
                $table->decimal('original_price', 12, 2);
                $table->json('original_period_prices')->nullable();
                $table->json('migrated_period_prices');
                $table->timestamp('created_at')->useCurrent();
            });
        }

        // Only course templates are changed. Admission snapshots, deals and payments are untouched.
        DB::table('courses')->whereNull('deleted_at')->where('duration_value', '>=', 1)
            ->whereIn('duration_unit', ['days', 'weeks', 'months', 'years'])
            ->whereNotNull('price')->where('price', '>=', 0)
            ->where(function ($query) {
                $query->whereNull('period_prices')->orWhereJsonLength('period_prices', 0);
            })->orderBy('id')->chunkById(200, function ($courses) {
                foreach ($courses as $candidate) {
                    DB::transaction(function () use ($candidate) {
                        $course = DB::table('courses')->where('id', $candidate->id)->lockForUpdate()->first();
                        if (! $course || $course->deleted_at || $course->duration_value < 1
                            || ! in_array($course->duration_unit, ['days', 'weeks', 'months', 'years'], true)
                            || $course->price === null || (float) $course->price < 0
                            || ! empty(json_decode($course->period_prices ?? 'null', true))) {
                            return;
                        }
                        // Do not overwrite an earlier backup when a deployment is retried.
                        if (DB::table('course_period_price_backups')->where('course_id', $course->id)->exists()) {
                            return;
                        }
                        $prices = json_encode([['period' => 1, 'amount' => (float) $course->price]]);
                        DB::table('course_period_price_backups')->insert([
                            'course_id' => $course->id, 'duration_value' => $course->duration_value,
                            'duration_unit' => $course->duration_unit, 'original_price' => $course->price,
                            'original_period_prices' => $course->period_prices, 'migrated_period_prices' => $prices,
                            'created_at' => now(),
                        ]);
                        DB::table('courses')->where('id', $course->id)->update(['period_prices' => $prices]);
                    });
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('course_period_price_backups')) {
            return;
        }
        DB::table('course_period_price_backups')->orderBy('course_id')->chunkById(200, function ($backups) {
            foreach ($backups as $backup) {
                DB::transaction(function () use ($backup) {
                    $course = DB::table('courses')->where('id', $backup->course_id)->lockForUpdate()->first();
                    // Restore only untouched templates; preserve any deliberate changes made afterwards.
                    if ($course && (int) $course->duration_value === (int) $backup->duration_value
                        && $course->duration_unit === $backup->duration_unit
                        && (string) $course->price === (string) $backup->original_price
                        && json_decode($course->period_prices ?? 'null', true) == json_decode($backup->migrated_period_prices, true)) {
                        DB::table('courses')->where('id', $course->id)->update(['period_prices' => $backup->original_period_prices]);
                        DB::table('course_period_price_backups')->where('course_id', $backup->course_id)->delete();
                    }
                });
            }
        }, 'course_id');
        // Retain backups for courses edited since migration, so rollback never erases their audit evidence.
        if (! DB::table('course_period_price_backups')->exists()) {
            Schema::drop('course_period_price_backups');
        }
    }
};

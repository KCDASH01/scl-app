<?php

namespace App\Console\Commands;

use App\Models\Course;
use App\Services\FirstPeriodConversionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertFirstPeriodFees extends Command
{
    protected $signature = 'fees:convert-first-period {--apply : Commit the reviewed conversion} {--course= : Limit to one course ID}';

    protected $description = 'Preview or apply client-confirmed legacy first-period fees, preserving payments and backing up original records';

    public function handle(FirstPeriodConversionService $service): int
    {
        $this->info($this->option('apply') ? 'APPLY: each course and its admissions commit atomically.' : 'PREVIEW: all writes roll back. Run during a maintenance window before --apply.');
        $failed = false;
        $query = Course::query()->orderBy('id');
        if ($this->option('course')) {
            if (! ctype_digit((string) $this->option('course')) || ! Course::whereKey($this->option('course'))->exists()) {
                $this->error('Select an existing, non-deleted course ID.');

                return self::FAILURE;
            }
            $query->whereKey($this->option('course'));
        }
        $query->chunkById(100, function ($courses) use ($service, &$failed) {
            foreach ($courses as $course) {
                DB::beginTransaction();
                try {
                    $counts = $service->convert($course);
                    if ($this->option('apply')) {
                        DB::commit();
                    } else {
                        DB::rollBack();
                    }
                    $this->line("Course {$course->id} ({$course->name}): ".json_encode($counts));
                } catch (ValidationException $e) {
                    DB::rollBack();
                    $failed = true;
                    $this->error("Course {$course->id} unchanged: ".json_encode($e->errors()));
                } catch (\Throwable $e) {
                    DB::rollBack();
                    throw $e;
                }
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

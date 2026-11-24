<?php

namespace App\Console\Commands;

use App\Models\Admission;
use App\Models\Course;
use App\Services\FirstPeriodConversionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertFirstPeriodFees extends Command
{
    protected $signature = 'fees:convert-first-period {--apply : Commit the reviewed conversion} {--course= : Limit to one course ID}';

    protected $description = 'Preview or apply active legacy first-period fees with admission-level isolation and financial reconciliation';

    private array $summary;

    private function payments(): array
    {
        // Include ALL historical payments, including those attached to deleted deals.
        $totals = DB::table('deal_payments')->selectRaw('COUNT(*) as records, COALESCE(SUM(amount), 0) as total')->first();

        return ['count' => (int) $totals->records, 'total' => number_format((float) $totals->total, 2, '.', '')];
    }

    public function handle(FirstPeriodConversionService $service): int
    {
        $service->assertTransactionalStorage();
        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY: each admission commits independently. Course templates commit separately.' : 'PREVIEW: same conversion/validation; all writes, backups and audits roll back.');
        $this->comment('Pause writes across all apps before either mode. Excluded = deleted/missing lead; skipped = manual financial review.');
        $query = Course::query()->orderBy('id');
        if ($this->option('course') !== null) {
            if (! ctype_digit((string) $this->option('course')) || ! Course::whereKey($this->option('course'))->exists()) {
                $this->error('Select an existing, non-deleted course ID.');

                return self::FAILURE;
            }
            $query->whereKey($this->option('course'));
        }
        $this->summary = array_fill_keys(['courses_examined', 'courses_converted', 'courses_already_converted', 'courses_skipped',
            'admissions_examined', 'eligible_admissions', 'admissions_converted', 'admissions_excluded_lead',
            'admissions_skipped_financial', 'admissions_already_converted', 'admissions_blocked_course',
            'course_deals_converted', 'hostel_deals_converted', 'payment_allocations_generated'], 0);
        $before = $this->payments();
        $completed = false;
        try {
            $query->chunkById(100, function ($courses) use ($service, $apply) {
                foreach ($courses as $course) {
                    $this->summary['courses_examined']++;
                    $level = DB::transactionLevel();
                    if (! $apply) {
                        // Keep the hypothetical course update visible to admission savepoints.
                        DB::beginTransaction();
                    }
                    try {
                        try {
                            $changed = DB::transaction(fn () => $service->prepareCourse($course->id));
                        } catch (ValidationException $e) {
                            $this->summary['courses_skipped']++;
                            $blockedIds = Admission::where('course_id', $course->id)->orderBy('id')->pluck('id')->all();
                            $this->summary['admissions_blocked_course'] += count($blockedIds);
                            $this->summary['admissions_examined'] += count($blockedIds);
                            $this->warn("Course {$course->id} SKIPPED: ".json_encode($e->errors(), JSON_THROW_ON_ERROR));
                            $this->line('Admissions blocked by this course: '.json_encode($blockedIds, JSON_THROW_ON_ERROR));

                            continue;
                        }
                        $this->summary[$changed ? 'courses_converted' : 'courses_already_converted']++;
                        $this->line("Course {$course->id}: ".($changed ? 'template converted' : 'template already configured'));
                        Admission::where('course_id', $course->id)->orderBy('id')->chunkById(100, function ($admissions) use ($service, $course) {
                            foreach ($admissions as $admission) {
                                $this->summary['admissions_examined']++;
                                try {
                                    $result = DB::transaction(fn () => $service->convertAdmission($admission->id, $course->id));
                                } catch (ValidationException $e) {
                                    $this->summary['admissions_skipped_financial']++;
                                    $this->warn("Admission {$admission->id} (course {$course->id}) SKIPPED: ".json_encode($e->errors(), JSON_THROW_ON_ERROR));

                                    continue;
                                }
                                if ($result['outcome'] === 'excluded') {
                                    $this->summary['admissions_excluded_lead']++;
                                    $this->line("Admission {$admission->id} EXCLUDED: {$result['reason']}");
                                } elseif ($result['outcome'] === 'already') {
                                    $this->summary['admissions_already_converted']++;
                                    $this->line("Admission {$admission->id}: already converted (validated; unchanged).");
                                } else {
                                    $this->summary['eligible_admissions']++;
                                    $this->summary['admissions_converted']++;
                                    $this->summary['course_deals_converted'] += $result['course_deals'];
                                    $this->summary['hostel_deals_converted'] += $result['hostel_deals'];
                                    $this->summary['payment_allocations_generated'] += $result['allocations'];
                                    $this->line("Admission {$admission->id} CONVERTED: ".json_encode($result['fees'], JSON_THROW_ON_ERROR));
                                }
                            }
                        });
                    } finally {
                        if (! $apply) {
                            while (DB::transactionLevel() > $level) {
                                DB::rollBack();
                            }
                        }
                    }
                }
            });
            $completed = true;
        } finally {
            $after = $this->payments();
            $this->line('SUMMARY '.json_encode(['mode' => $apply ? 'apply' : 'preview', 'completed' => $completed,
                'counts' => $this->summary, 'payments_before' => $before, 'payments_after' => $after,
                'payment_counts_and_totals_unchanged' => $before === $after], JSON_THROW_ON_ERROR));
            if ($before !== $after) {
                throw new \RuntimeException('Historical payment counts/totals changed during conversion. Stop and investigate; concurrent writes must be paused.');
            }
        }
        if (! $apply) {
            $this->comment('Preview CONVERTED counts are hypothetical; nothing was persisted. Eligible = newly convertible legacy admissions; already-converted admissions are counted separately.');
        }

        return $this->summary['courses_skipped'] || $this->summary['admissions_skipped_financial'] ? self::FAILURE : self::SUCCESS;
    }
}

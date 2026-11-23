<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Course;
use App\Models\Deal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Explicit conversion of client-confirmed first-period legacy agreements. */
class FirstPeriodConversionService
{
    private function backup(string $entity, $model): void
    {
        DB::table('first_period_conversion_backups')->insertOrIgnore([
            'entity' => $entity, 'record_id' => $model->id,
            'original_record' => json_encode($model->getRawOriginal(), JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }

    // Caller owns a transaction; preview runs the same validation and rolls back every write.
    public function convert(Course $candidate): array
    {
        $fees = app(FeeScheduleService::class);
        $course = Course::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
        $counts = ['courses' => 0, 'admissions' => 0, 'deals' => 0, 'fees' => []];
        if (! $course->duration_value || ! $course->duration_unit) {
            if ($course->duration_value || $course->duration_unit) {
                throw ValidationException::withMessages(['course' => 'Incomplete duration; review this course manually.']);
            }
            if ($course->price === null || $fees->cents($course->price) < 0 || $course->period_prices) {
                throw ValidationException::withMessages(['course' => 'Missing/invalid price or ambiguous period prices.']);
            }
            $this->backup('courses', $course);
            $course->update(['duration_value' => 1, 'duration_unit' => 'years', 'period_prices' => [['period' => 1, 'amount' => (float) $course->price]]]);
            $counts['courses']++;
        } elseif (! $course->period_prices) {
            if ($course->price === null) {
                throw ValidationException::withMessages(['course' => 'Missing first period price.']);
            }
            $this->backup('courses', $course);
            $course->update(['period_prices' => [['period' => 1, 'amount' => (float) $course->price]]]);
            $counts['courses']++;
        }
        $fees->normalize($course->period_prices, (int) $course->duration_value);
        $admissions = Admission::where('course_id', $course->id)->whereNull('fee_schedule')->orderBy('id')->lockForUpdate()->get();
        foreach ($admissions as $admission) {
            if (Admission::where('lead_id', $admission->lead_id)->count() !== 1) {
                throw ValidationException::withMessages(['admission' => "Duplicate admissions for lead {$admission->lead_id}."]);
            }
            $deals = Deal::where('lead_id', $admission->lead_id)->whereIn('fee_kind', ['course', 'hostel'])->orderBy('id')->lockForUpdate()->get();
            if ($deals->where('fee_kind', 'course')->count() !== 1 || $deals->where('fee_kind', 'hostel')->count() > 1 || $deals->contains(fn ($d) => (bool) $d->fee_periods)) {
                throw ValidationException::withMessages(['admission' => "Admission {$admission->id}: missing/duplicate fee or mixed schedules; review manually."]);
            }
            $courseDeal = $deals->firstWhere('fee_kind', 'course');
            $hostelDeal = $deals->firstWhere('fee_kind', 'hostel');
            if ($admission->hostel_stay && ! $hostelDeal) {
                throw ValidationException::withMessages(['hostel' => "Admission {$admission->id}: hostel selected but no hostel fee record; review manually."]);
            }
            $this->backup('admissions', $admission);
            $row = ['period' => 1, 'amount' => (float) $courseDeal->value,
                'hostel_stay' => (bool) $hostelDeal || (bool) $admission->hostel_stay,
                'hostel_amount' => $hostelDeal ? (float) $hostelDeal->value : null];
            $schedule = ['unit' => $course->duration_unit, 'duration' => (int) $course->duration_value, 'rows' => [$row]];
            foreach ($deals as $deal) {
                $payments = $deal->payments()->orderBy('id')->lockForUpdate()->get();
                $total = $fees->cents($deal->value);
                $received = $payments->sum(fn ($p) => $fees->cents($p->amount));
                if ($total < 0 || $payments->contains(fn ($p) => $fees->cents($p->amount) <= 0)
                    || $received > $total || $fees->cents($deal->received_amount ?? 0) > $received || ($deal->status === 'won' && $received !== $total)) {
                    throw ValidationException::withMessages(['deal' => "Fee {$deal->id}: payment total does not reconcile with value/status; review manually."]);
                }
                $this->backup('deals', $deal);
                $counts['fees'][] = ['admission_id' => $admission->id, 'deal_id' => $deal->id,
                    'kind' => $deal->fee_kind, 'unit' => $course->duration_unit, 'period' => 1,
                    'amount' => $total / 100, 'received' => $received / 100,
                    'remaining' => ($total - $received) / 100, 'status' => $deal->status];
                $before = ['schedule' => $deal->toArray(), 'payments' => $payments->toArray()];
                $running = 0;
                foreach ($payments as $payment) {
                    $this->backup('deal_payments', $payment);
                    $running += $fees->cents($payment->amount);
                    if (! $payment->receipt_snapshot) {
                        $payment->update(['receipt_snapshot' => [
                            'unit' => $course->duration_unit, 'fee_kind' => $deal->fee_kind,
                            'total_fee' => $total / 100, 'total_received' => $running / 100,
                            'due_amount' => ($total - $running) / 100,
                            'allocations' => [['period' => 1, 'amount' => (float) $payment->amount, 'remaining' => ($total - $running) / 100]],
                        ]]);
                    }
                }
                $fees->setDealSchedule($deal, $schedule, [['period' => 1, 'amount' => (float) $deal->value]]);
                // Preserve original status/close date/stage during the initial conversion.
                $status = $deal->only(['status', 'closed_at', 'stage_id']);
                $fees->allocate($deal);
                $deal->update($status);
                DB::table('fee_audits')->insert([
                    'deal_id' => $deal->id, 'action' => 'first_period_converted', 'actor_id' => null,
                    'reason' => 'Client confirmed legacy fees cover period 1. Converted by fees:convert-first-period.',
                    'before' => json_encode($before), 'after' => json_encode(['schedule' => $deal->fresh()->toArray(), 'payments' => $deal->payments()->orderBy('id')->get()->toArray()]), 'created_at' => now(),
                ]);
                $counts['deals']++;
            }
            $admission->update(['fee_schedule' => $schedule, 'hostel_stay' => $row['hostel_stay'], 'hostel_amount' => $row['hostel_amount']]);
            $counts['admissions']++;
        }

        return $counts;
    }
}

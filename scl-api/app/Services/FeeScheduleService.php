<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Course;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FeeScheduleService
{
    /** Called inside the course update transaction. Existing student agreements are immutable here. */
    public function extendCourse(Course $course, User $actor): void
    {
        Admission::where('course_id', $course->id)->orderBy('id')->chunkById(100, function ($admissions) use ($course, $actor) {
            foreach ($admissions as $candidate) {
                $admission = Admission::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                $old = $admission->fee_schedule;
                if (! $old) {
                    throw ValidationException::withMessages(['period_prices' => 'Convert existing admissions with fees:convert-first-period before changing this course.']);
                }
                $last = max(array_column($old['rows'], 'period'));
                if ($course->duration_unit !== $old['unit'] || (int) $course->duration_value < $last) {
                    throw ValidationException::withMessages(['duration_value' => 'Keep the existing student fee unit and a duration covering all assigned periods.']);
                }
                $rows = $old['rows'];
                foreach ($course->period_prices ?? [] as $price) {
                    if ($price['period'] > $last) {
                        $rows[] = ['period' => $price['period'], 'amount' => $price['amount'], 'hostel_stay' => false, 'hostel_amount' => null];
                    }
                }
                $schedule = array_merge($old, ['duration' => (int) $course->duration_value, 'rows' => $rows]);
                if ($schedule == $old) {
                    continue;
                }
                $this->normalize($rows, $schedule['duration']);
                $deals = Deal::where('lead_id', $admission->lead_id)->orderBy('id')->lockForUpdate()->get();
                foreach ($deals as $deal) {
                    if (! $deal->fee_periods || ! in_array($deal->fee_kind, ['course', 'hostel'], true)) {
                        continue;
                    }
                    $before = ['schedule' => $deal->toArray(), 'payments' => $deal->payments()->orderBy('id')->get()->toArray()];
                    $prices = $deal->fee_kind === 'hostel'
                        ? array_map(fn ($r) => ['period' => $r['period'], 'amount' => $r['hostel_amount'] ?? 0, 'applicable' => (bool) $r['hostel_stay']], $rows)
                        : $rows;
                    $this->setDealSchedule($deal, $schedule, $prices);
                    // Lost records are retained until explicitly resumed by the operator.
                    if ($deal->status !== 'lost') {
                        $this->allocate($deal);
                    }
                    $this->audit($deal, $actor, 'course_periods_added', $before, ['schedule' => $deal->fresh()->toArray(), 'payments' => $deal->payments()->orderBy('id')->get()->toArray()], null, 'Course duration/pricing extended; existing student prices preserved.');
                }
                $admission->update(['fee_schedule' => $schedule]);
            }
        });
    }

    public function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public function normalize(array $rows, int $duration): array
    {
        if (! $rows || $duration < 1) {
            throw ValidationException::withMessages(['fee_periods' => 'Add at least one price and a valid duration.']);
        }
        $seen = [];
        foreach ($rows as &$row) {
            $period = filter_var($row['period'] ?? null, FILTER_VALIDATE_INT);
            if (! $period || $period < 1 || $period > $duration || isset($seen[$period])) {
                throw ValidationException::withMessages(['fee_periods' => 'Period numbers must be unique whole numbers within the course duration.']);
            }
            if (! isset($row['amount']) || ! is_numeric($row['amount']) || $row['amount'] < 0 || $row['amount'] > 9999999999.99) {
                throw ValidationException::withMessages(['fee_periods' => 'Enter a valid non-negative price for every period.']);
            }
            $seen[$period] = true;
            $row['period'] = $period;
            $row['amount'] = $this->cents($row['amount']) / 100;
        }
        unset($row);
        if (array_sum(array_map(fn ($r) => $this->cents($r['amount']), $rows)) > 999999999999) {
            throw ValidationException::withMessages(['fee_periods' => 'The total fee exceeds the supported amount.']);
        }
        usort($rows, fn ($a, $b) => $a['period'] <=> $b['period']);

        return $rows;
    }

    public function initialize(Admission $admission, ?array $prices = null): void
    {
        $course = $admission->course;
        if ($admission->fee_schedule || ! $course?->period_prices) {
            return;
        }
        $rows = $this->normalize($prices ?? $course->period_prices, $course->duration_value);
        $master = $this->normalize($course->period_prices, $course->duration_value);
        if (array_column($rows, 'period') !== array_column($master, 'period')) {
            throw ValidationException::withMessages(['fee_periods' => 'Keep the configured course period numbers. You can change each price for this student.']);
        }
        $rows = array_map(fn ($row) => ['period' => $row['period'], 'amount' => $row['amount'], 'hostel_stay' => false, 'hostel_amount' => null], $rows);
        $schedule = ['unit' => $course->duration_unit, 'duration' => $course->duration_value, 'rows' => $rows];
        $admission->update(['fee_schedule' => $schedule]);
        $deal = Deal::where('lead_id', $admission->lead_id)->where('fee_kind', 'course')->latest('id')->first();
        if ($deal) {
            $this->setDealSchedule($deal, $schedule, $rows);
            $this->allocate($deal);
        }
    }

    public function setDealSchedule(Deal $deal, array $schedule, array $rows): void
    {
        $prices = array_map(fn ($row) => ['period' => (int) $row['period'], 'amount' => (float) $row['amount'], 'applicable' => $row['applicable'] ?? true], $rows);
        $deal->update([
            'fee_periods' => $prices, 'fee_unit' => $schedule['unit'], 'fee_duration' => $schedule['duration'],
            'value' => array_sum(array_map(fn ($row) => $this->cents($row['amount']), $prices)) / 100,
        ]);
    }

    public function syncAdmission(Admission $admission, array $rows, User $actor, ?string $reason = null): void
    {
        $old = $admission->fee_schedule;
        if (! $old) {
            // Legacy whole-course records must never be silently converted.
            throw ValidationException::withMessages(['fee_periods' => 'This admission uses legacy whole-course fees. Its existing payments are preserved.']);
        }
        $rows = $this->normalize($rows, (int) $old['duration']);
        $expected = array_column($old['rows'], 'period');
        if (array_column($rows, 'period') !== $expected) {
            throw ValidationException::withMessages(['fee_periods' => 'Keep the admission’s original period numbers. Only prices and hostel stay can be changed.']);
        }
        foreach ($rows as &$row) {
            $row['hostel_stay'] = (bool) ($row['hostel_stay'] ?? false);
            if ($row['hostel_stay'] && (! isset($row['hostel_amount']) || ! is_numeric($row['hostel_amount']) || $row['hostel_amount'] < 0)) {
                throw ValidationException::withMessages(['fee_periods' => 'Enter a hostel fee for every period marked Stay.']);
            }
            $row['hostel_amount'] = $row['hostel_stay'] ? $this->cents($row['hostel_amount']) / 100 : null;
        }
        unset($row);
        if (array_sum(array_map(fn ($r) => $this->cents($r['hostel_amount'] ?? 0), $rows)) > 999999999999) {
            throw ValidationException::withMessages(['fee_periods' => 'The total hostel fee exceeds the supported amount.']);
        }
        $schedule = array_merge($old, ['rows' => $rows]);
        $deals = Deal::where('lead_id', $admission->lead_id)->orderBy('id')->lockForUpdate()->get();
        foreach ($deals as $deal) {
            if (! $deal->fee_periods) {
                continue;
            }
            $nextRows = $deal->fee_kind === 'hostel'
                ? array_map(fn ($r) => ['period' => $r['period'], 'amount' => $r['hostel_stay'] ? $r['hostel_amount'] : 0, 'applicable' => (bool) $r['hostel_stay']], $rows)
                : $rows;
            $newPrices = array_column($nextRows, 'amount', 'period');
            $periods = $this->summary($deal);
            foreach ($periods as $period) {
                if ($period['received'] > 0 && $this->cents($newPrices[$period['period']] ?? 0) !== $this->cents($period['amount'])) {
                    if (! $actor->isAdmin()) {
                        abort(403, 'Only admins and managers can change prices for periods with payments.');
                    }
                    if (! trim((string) $reason)) {
                        throw ValidationException::withMessages(['fee_change_reason' => 'Enter a reason for changing a period with payments.']);
                    }
                }
            }
            if ($this->cents(array_sum($newPrices)) < $this->cents($deal->payments()->sum('amount'))) {
                throw ValidationException::withMessages(['fee_periods' => 'The new fee cannot be less than the payments already recorded.']);
            }
        }
        $admission->update([
            'fee_schedule' => $schedule,
            'hostel_stay' => collect($rows)->contains('hostel_stay', true),
            'hostel_amount' => array_sum(array_column($rows, 'hostel_amount')),
        ]);
        $courseDeal = $deals->firstWhere('fee_kind', 'course');
        if ($courseDeal) {
            $before = ['schedule' => $courseDeal->toArray(), 'payments' => $courseDeal->payments()->orderBy('id')->get()->toArray()];
            $this->setDealSchedule($courseDeal, $schedule, $rows);
            $this->allocate($courseDeal);
            if ($old != $schedule) {
                $this->audit($courseDeal, $actor, 'schedule_updated', $before, ['schedule' => $courseDeal->fresh()->toArray(), 'payments' => $courseDeal->payments()->orderBy('id')->get()->toArray()], null, $reason);
            }
        }
        $hostelDeal = app(AdmissionService::class)->syncHostelFee($admission->fresh()->load('lead'), $actor);
        if ($hostelDeal) {
            $before = ['schedule' => $hostelDeal->toArray(), 'payments' => $hostelDeal->payments()->orderBy('id')->get()->toArray()];
            $hostelRows = array_map(fn ($r) => ['period' => $r['period'], 'amount' => $r['hostel_stay'] ? $r['hostel_amount'] : 0, 'applicable' => (bool) $r['hostel_stay']], $rows);
            $this->setDealSchedule($hostelDeal, $schedule, $hostelRows);
            $this->allocate($hostelDeal);
            if ($old != $schedule) {
                $this->audit($hostelDeal, $actor, 'schedule_updated', $before, ['schedule' => $hostelDeal->fresh()->toArray(), 'payments' => $hostelDeal->payments()->orderBy('id')->get()->toArray()], null, $reason);
            }
        }
    }

    public function summary(Deal $deal): array
    {
        if (! $deal->fee_periods) {
            return [];
        }
        $remainingReceived = $this->cents($deal->payments_sum_amount ?? $deal->payments()->sum('amount'));
        $currentFound = false;

        return array_map(function ($row) use (&$remainingReceived, &$currentFound) {
            $amount = $this->cents($row['amount']);
            $received = min($amount, max(0, $remainingReceived));
            $remainingReceived -= $received;
            $due = $amount - $received;
            $state = $due === 0 ? (($row['applicable'] ?? true) ? 'paid' : 'not_applicable') : ($currentFound ? 'upcoming' : 'current');
            if ($due > 0) {
                $currentFound = true;
            }

            return ['period' => $row['period'], 'amount' => $amount / 100, 'received' => $received / 100, 'remaining' => $due / 100, 'state' => $state];
        }, $deal->fee_periods);
    }

    // Caller holds the parent deal lock. Allocate by transaction ID so backdating cannot reorder history.
    public function allocate(Deal $deal, ?int $editedPaymentId = null): void
    {
        if (! $deal->fee_periods) {
            return;
        }
        $payments = $deal->payments()->orderBy('id')->lockForUpdate()->get();
        $capacity = array_map(fn ($r) => $this->cents($r['amount']), $deal->fee_periods);
        $total = array_sum($capacity);
        if ($payments->sum(fn ($p) => $this->cents($p->amount)) > $total) {
            throw ValidationException::withMessages(['amount' => 'Payment exceeds the remaining total fee balance.']);
        }
        $running = 0;
        foreach ($payments as $payment) {
            $left = $this->cents($payment->amount);
            $allocations = [];
            foreach ($deal->fee_periods as $i => $period) {
                $take = min($left, $capacity[$i]);
                if ($take > 0) {
                    $capacity[$i] -= $take;
                    $left -= $take;
                    $allocations[] = ['period' => $period['period'], 'amount' => $take / 100, 'remaining' => $capacity[$i] / 100];
                }
            }
            $running += $this->cents($payment->amount);
            $fields = ['allocations' => $allocations];
            if (! $payment->receipt_snapshot || $payment->id === $editedPaymentId) {
                $fields['receipt_snapshot'] = [
                    'unit' => $deal->fee_unit, 'fee_kind' => $deal->fee_kind, 'total_fee' => $total / 100,
                    'total_received' => $running / 100, 'due_amount' => ($total - $running) / 100,
                    'allocations' => $allocations,
                ];
            }
            $payment->update($fields);
        }
        $fullyPaid = $running >= $total;
        $stage = $deal->pipeline?->stages()
            ->where('is_won', $fullyPaid)->where('is_lost', false)->orderBy('sort_order')->first();
        $fields = ['status' => $fullyPaid ? 'won' : 'open', 'closed_at' => $fullyPaid ? ($deal->closed_at?->toDateString() ?? now()->toDateString()) : null];
        if ($stage) {
            $fields['stage_id'] = $stage->id;
        }
        $deal->update($fields);
    }

    public function audit(Deal $deal, User $actor, string $action, array $before, array $after, ?int $paymentId = null, ?string $reason = null): void
    {
        DB::table('fee_audits')->insert([
            'deal_id' => $deal->id, 'payment_id' => $paymentId, 'actor_id' => $actor->id,
            'action' => $action, 'reason' => $reason, 'before' => json_encode($before), 'after' => json_encode($after), 'created_at' => now(),
        ]);
    }
}

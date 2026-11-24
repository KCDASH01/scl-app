<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Course;
use App\Models\Deal;
use App\Models\Lead;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Client-confirmed legacy agreements only. All mutations require a caller-owned transaction. */
class FirstPeriodConversionService
{
    public function assertTransactionalStorage(): void
    {
        $connection = DB::connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        $required = ['courses', 'leads', 'admissions', 'deals', 'deal_payments', 'first_period_conversion_backups', 'fee_audits'];
        $engines = $connection->table('information_schema.TABLES')->where('TABLE_SCHEMA', $connection->getDatabaseName())
            ->whereIn('TABLE_NAME', $required)->pluck('ENGINE', 'TABLE_NAME');
        foreach ($required as $table) {
            if (strtolower((string) ($engines[$table] ?? '')) !== 'innodb') {
                throw new \RuntimeException("Conversion refused: {$table} is missing or does not use InnoDB. Transactional storage is required for preview and admission rollback. No automatic schema repair is performed.");
            }
        }
    }

    private function invalid(string $reason): never
    {
        throw ValidationException::withMessages(['conversion' => $reason]);
    }

    private function backup(string $entity, $model): void
    {
        if (! DB::table('first_period_conversion_backups')->where('entity', $entity)->where('record_id', $model->id)->exists()) {
            // Parent course/admission locks serialize this check. Do not suppress storage/SQL failures.
            DB::table('first_period_conversion_backups')->insert([
                'entity' => $entity, 'record_id' => $model->id,
                'original_record' => json_encode($model->getRawOriginal(), JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
        }
    }

    public function prepareCourse(int $courseId): bool
    {
        $course = Course::whereKey($courseId)->lockForUpdate()->first();
        if (! $course) {
            $this->invalid('Course is deleted or missing.');
        }
        $duration = $course->duration_value;
        $unit = $course->duration_unit;
        $prices = $course->period_prices;
        if ($duration === null && $unit === null) {
            if ($prices !== null && $prices !== []) {
                $this->invalid('Period prices without a duration require manual review.');
            }
            $duration = 1;
            $unit = 'years';
        }
        if (! in_array($unit, Course::DURATION_UNITS, true) || ! filter_var($duration, FILTER_VALIDATE_INT) || $duration < 1) {
            $this->invalid('Incomplete or invalid course duration/unit.');
        }
        if ($course->price === null || ! is_numeric($course->price) || ! is_finite((float) $course->price) || $course->price < 0 || $course->price > 9999999999.99) {
            $this->invalid('Missing or invalid course price.');
        }
        $prices = $prices ?: [['period' => 1, 'amount' => (float) $course->price]];
        if (! is_array($prices) || collect($prices)->contains(fn ($r) => ! is_array($r))) {
            $this->invalid('Malformed course period prices.');
        }
        app(FeeScheduleService::class)->normalize($prices, (int) $duration);
        $changed = $course->duration_value !== $duration || $course->duration_unit !== $unit || $course->period_prices != $prices;
        if ($changed) {
            $this->backup('courses', $course);
            $course->update(['duration_value' => $duration, 'duration_unit' => $unit, 'period_prices' => $prices]);
        }

        return $changed;
    }

    public function convertAdmission(int $admissionId, int $courseId): array
    {
        $course = Course::whereKey($courseId)->lockForUpdate()->first();
        $admission = Admission::whereKey($admissionId)->where('course_id', $courseId)->lockForUpdate()->first();
        if (! $course || ! $admission) {
            $this->invalid('Course/admission is deleted, missing or no longer associated.');
        }
        // Default SoftDeletes scope is intentional. Never restore a historical lead.
        $lead = Lead::whereKey($admission->lead_id)->lockForUpdate()->first();
        if (! $lead) {
            return ['outcome' => 'excluded', 'reason' => 'Associated lead is deleted or missing.'];
        }
        if (Admission::where('lead_id', $lead->id)->count() !== 1) {
            $this->invalid('Duplicate active admission relationships for this lead.');
        }
        if ((int) $lead->branch_id !== (int) $admission->branch_id || (int) $course->branch_id !== (int) $admission->branch_id
            || ($lead->course_id !== null && (int) $lead->course_id !== $courseId)) {
            $this->invalid('Course, admission and lead relationships are inconsistent.');
        }
        // Deleted deals are read only to detect ambiguity; their payments are never converted.
        $allDeals = Deal::withTrashed()->where('lead_id', $lead->id)->whereIn('fee_kind', ['course', 'hostel'])->orderBy('id')->lockForUpdate()->get();
        foreach ($allDeals as $deal) {
            if ($deal->trashed() && $deal->payments()->exists()) {
                $this->invalid("Deleted {$deal->fee_kind} deal {$deal->id} has historical payments; financial relationship requires manual review.");
            }
        }
        $deals = $allDeals->reject(fn ($d) => $d->trashed());
        $courseDeals = $deals->where('fee_kind', 'course');
        if ($courseDeals->count() !== 1) {
            $this->invalid($courseDeals->isEmpty() ? 'Missing active course fee deal.' : 'Duplicate active course fee deals.');
        }
        if ($deals->where('fee_kind', 'hostel')->count() > 1) {
            $this->invalid('Duplicate active hostel fee deals.');
        }
        $courseDeal = $courseDeals->first();
        $hostelDeal = $deals->firstWhere('fee_kind', 'hostel');
        if ($admission->hostel_stay && ! $hostelDeal) {
            $this->invalid('Hostel stay requires an active hostel fee deal.');
        }
        $fees = app(FeeScheduleService::class);
        if ($admission->fee_schedule === null && $admission->hostel_stay && $hostelDeal && $admission->hostel_amount !== null
            && $fees->cents($admission->hostel_amount) !== $fees->cents($hostelDeal->value)) {
            $this->invalid('Legacy admission hostel amount differs from the active hostel fee; review the agreement manually.');
        }
        $paymentsByDeal = [];
        foreach ($deals as $deal) {
            if ((int) $deal->branch_id !== (int) $admission->branch_id) {
                $this->invalid("Deal {$deal->id} belongs to a different branch.");
            }
            $fees->normalize([['period' => 1, 'amount' => $deal->value]], 1);
            $payments = $deal->payments()->orderBy('id')->lockForUpdate()->get();
            $received = 0;
            foreach ($payments as $payment) {
                if (! is_numeric($payment->amount) || ! is_finite((float) $payment->amount) || $payment->amount <= 0
                    || $payment->amount > 9999999999.99 || (int) $payment->branch_id !== (int) $deal->branch_id) {
                    $this->invalid("Payment {$payment->id} has an invalid amount or branch relationship.");
                }
                $received += $fees->cents($payment->amount);
            }
            $total = $fees->cents($deal->value);
            if ($received > $total || $fees->cents($deal->received_amount ?? 0) < 0
                || $fees->cents($deal->received_amount ?? 0) > $received || ($deal->status === 'won' && $received !== $total)) {
                $this->invalid("Deal {$deal->id}: payments do not reconcile with fee value/status.");
            }
            $paymentsByDeal[$deal->id] = $payments;
        }
        if ($admission->fee_schedule !== null) {
            $this->validateExistingSchedule($admission, $deals, $paymentsByDeal);

            return ['outcome' => 'already'];
        }
        foreach ($deals as $deal) {
            if ($deal->fee_periods !== null || $deal->fee_unit !== null || $deal->fee_duration !== null
                || $paymentsByDeal[$deal->id]->contains(fn ($p) => $p->allocations !== null)) {
                $this->invalid("Deal {$deal->id}: mixed schedules or existing allocations without an admission schedule.");
            }
        }
        if (DB::table('first_period_conversion_backups')->where('entity', 'admissions')->where('record_id', $admission->id)->exists()) {
            $this->invalid('Prior conversion backup exists but admission schedule is missing; review manually.');
        }
        $this->backup('admissions', $admission);
        $row = ['period' => 1, 'amount' => (float) $courseDeal->value, 'hostel_stay' => (bool) $hostelDeal,
            'hostel_amount' => $hostelDeal ? (float) $hostelDeal->value : null];
        $schedule = ['unit' => $course->duration_unit, 'duration' => (int) $course->duration_value, 'rows' => [$row]];
        $result = ['outcome' => 'converted', 'course_deals' => 0, 'hostel_deals' => 0, 'allocations' => 0, 'fees' => []];
        foreach ($deals as $deal) {
            $payments = $paymentsByDeal[$deal->id];
            $total = $fees->cents($deal->value);
            $this->backup('deals', $deal);
            $before = ['schedule' => $deal->toArray(), 'payments' => $payments->toArray()];
            $fees->setDealSchedule($deal, $schedule, [['period' => 1, 'amount' => (float) $deal->value]]);
            $running = 0;
            foreach ($payments as $payment) {
                $this->backup('deal_payments', $payment);
                $running += $fees->cents($payment->amount);
                $allocation = [['period' => 1, 'amount' => (float) $payment->amount, 'remaining' => ($total - $running) / 100]];
                $fields = ['allocations' => $allocation];
                if ($payment->receipt_snapshot === null) {
                    $fields['receipt_snapshot'] = ['unit' => $course->duration_unit, 'fee_kind' => $deal->fee_kind,
                        'total_fee' => $total / 100, 'total_received' => $running / 100,
                        'due_amount' => ($total - $running) / 100, 'allocations' => $allocation];
                }
                $payment->update($fields);
                $result['allocations']++;
            }
            // Do not call allocate(): initial conversion must never recalculate status/stage/closed_at.
            DB::table('fee_audits')->insert([
                'deal_id' => $deal->id, 'action' => 'first_period_converted', 'actor_id' => null,
                'reason' => 'Client confirmed legacy fees cover period 1. Converted by fees:convert-first-period.',
                'before' => json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode(['schedule' => $deal->fresh()->toArray(), 'payments' => $deal->payments()->orderBy('id')->get()->toArray()], JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            $result[$deal->fee_kind.'_deals']++;
            $result['fees'][] = ['deal_id' => $deal->id, 'kind' => $deal->fee_kind, 'period' => 1,
                'amount' => $total / 100, 'received' => $running / 100, 'remaining' => ($total - $running) / 100, 'status' => $deal->status];
        }
        $admission->update(['fee_schedule' => $schedule, 'hostel_stay' => $row['hostel_stay'], 'hostel_amount' => $row['hostel_amount']]);

        return $result;
    }

    /** Validate idempotency without rewriting existing (potentially extended) schedules or receipts. */
    private function validateExistingSchedule(Admission $admission, Collection $deals, array $paymentsByDeal): void
    {
        $fees = app(FeeScheduleService::class);
        $schedule = $admission->fee_schedule;
        if (! is_array($schedule) || ! in_array($schedule['unit'] ?? null, Course::DURATION_UNITS, true)
            || ! is_array($schedule['rows'] ?? null) || collect($schedule['rows'])->contains(fn ($r) => ! is_array($r))) {
            $this->invalid('Malformed existing admission schedule.');
        }
        $duration = filter_var($schedule['duration'] ?? null, FILTER_VALIDATE_INT);
        $rows = $fees->normalize($schedule['rows'], (int) $duration);
        $hostelTotal = 0;
        $hasStay = false;
        foreach ($rows as $row) {
            if ($row['hostel_stay'] ?? false) {
                $hasStay = true;
                if (! isset($row['hostel_amount']) || ! is_numeric($row['hostel_amount']) || ! is_finite((float) $row['hostel_amount'])
                    || $row['hostel_amount'] < 0 || $row['hostel_amount'] > 9999999999.99) {
                    $this->invalid('Malformed existing hostel price.');
                }
                $hostelTotal += $fees->cents($row['hostel_amount']);
            }
        }
        if ($hasStay !== (bool) $admission->hostel_stay || $hostelTotal !== $fees->cents($admission->hostel_amount ?? 0)
            || ($hasStay && ! $deals->firstWhere('fee_kind', 'hostel'))) {
            $this->invalid('Admission hostel agreement and active hostel fee do not reconcile.');
        }
        foreach ($deals as $deal) {
            $expected = [];
            foreach ($rows as $row) {
                $stay = (bool) ($row['hostel_stay'] ?? false);
                $amount = $deal->fee_kind === 'hostel' ? ($stay ? ($row['hostel_amount'] ?? null) : 0) : $row['amount'];
                if (! is_numeric($amount) || $amount < 0) {
                    $this->invalid('Malformed existing hostel price.');
                }
                $expected[] = ['period' => $row['period'], 'amount' => $amount];
            }
            if (! is_array($deal->fee_periods) || collect($deal->fee_periods)->contains(fn ($r) => ! is_array($r))
                || $deal->fee_unit !== $schedule['unit'] || (int) $deal->fee_duration !== $duration) {
                $this->invalid("Deal {$deal->id}: mixed or inconsistent fee schedules.");
            }
            $actual = $fees->normalize($deal->fee_periods, $duration);
            $capacity = [];
            foreach ($expected as $i => $row) {
                if (($actual[$i]['period'] ?? null) !== $row['period'] || $fees->cents($actual[$i]['amount'] ?? -1) !== $fees->cents($row['amount'])
                    || (bool) ($actual[$i]['applicable'] ?? true) !== ($deal->fee_kind !== 'hostel' || (bool) ($rows[$i]['hostel_stay'] ?? false))) {
                    $this->invalid("Deal {$deal->id}: prices differ from the admission schedule.");
                }
                $capacity[$row['period']] = $fees->cents($row['amount']);
            }
            if (count($actual) !== count($expected) || array_sum($capacity) !== $fees->cents($deal->value)) {
                $this->invalid("Deal {$deal->id}: schedule total differs from recorded fee value.");
            }
            foreach ($paymentsByDeal[$deal->id] as $payment) {
                $expectedAllocation = [];
                $left = $fees->cents($payment->amount);
                foreach ($capacity as $period => &$available) {
                    $take = min($left, $available);
                    if ($take > 0) {
                        $available -= $take;
                        $left -= $take;
                        $expectedAllocation[] = ['period' => $period, 'amount' => $take / 100, 'remaining' => $available / 100];
                    }
                }
                unset($available);
                if ($payment->allocations != $expectedAllocation) {
                    $this->invalid("Payment {$payment->id}: existing allocations do not reconcile; no automatic repair.");
                }
            }
        }
    }
}

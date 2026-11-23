<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Course;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdmissionService
{
    public function __construct(private readonly EnrollmentIdGenerator $ids) {}

    public function createForConvertedLead(Lead $lead, ?array $prices = null): Admission
    {
        $existing = Admission::query()->where('lead_id', $lead->id)->first();
        if ($existing) {
            return $existing;
        }

        if (! $lead->branch_id) {
            throw new \InvalidArgumentException('A lead must belong to a branch before admission.');
        }

        return DB::transaction(function () use ($lead, $prices) {
            $again = Admission::query()->where('lead_id', $lead->id)->lockForUpdate()->first();
            if ($again) {
                return $again;
            }

            $courseId = $lead->course_id;
            if ($courseId) {
                $matches = Course::query()
                    ->whereKey($courseId)
                    ->where('branch_id', $lead->branch_id)
                    ->lockForUpdate()->first();
                if (! $matches) {
                    $courseId = null;
                }
            }

            $admission = Admission::create([
                'lead_id' => $lead->id,
                'branch_id' => $lead->branch_id,
                'course_id' => $courseId,
                'batch_id' => null,
                'admission_date' => now()->toDateString(),
                'status' => 'pending',
                'enrollment_id' => $this->ids->next((int) $lead->branch_id),
            ]);
            app(FeeScheduleService::class)->initialize($admission, $prices);

            return $admission;
        });
    }

    /**
     * A checked hostel stay becomes the student's hostel fee row.
     * Unchecking removes that row when nothing has been collected yet.
     */
    public function syncHostelFee(Admission $admission, User $actor): ?Deal
    {
        return DB::transaction(function () use ($admission, $actor) {
            $admission = Admission::whereKey($admission->id)->lockForUpdate()->firstOrFail();
            $admission->loadMissing('lead');
            $existing = Deal::query()
                ->where('lead_id', $admission->lead_id)
                ->where('fee_kind', 'hostel')
                ->first();

            if (! $admission->hostel_stay) {
                if ($existing && (float) $existing->payments()->sum('amount') <= 0) {
                    $existing->delete();
                }

                return null;
            }

            $name = trim((string) ($admission->lead->full_name ?? '')) ?: 'Student';
            $fields = [
                'title' => 'Hostel Fee — '.$name,
                'value' => $admission->hostel_amount,
            ];

            if ($existing) {
                if ($admission->fee_schedule && $existing->fee_periods) {
                    return $existing;
                }
                $existing->update($fields);

                return $existing;
            }

            $pipeline = app(PipelineSetupService::class)->createDefaultPipeline((int) $admission->branch_id);
            $stage = $pipeline->stages()->orderBy('sort_order')->get()
                ->first(fn ($stage) => ! $stage->is_won && ! $stage->is_lost)
                ?? $pipeline->stages()->orderBy('sort_order')->first();

            if (! $stage) {
                return null;
            }

            $deal = Deal::create([
                'branch_id' => $admission->branch_id,
                'lead_id' => $admission->lead_id,
                'fee_kind' => 'hostel',
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'created_by' => $actor->id,
                'assigned_to' => $admission->lead?->assigned_to,
                'title' => $fields['title'],
                'value' => $fields['value'],
                'currency' => 'INR',
                'status' => 'open',
            ]);
            if ($admission->fee_schedule) {
                $schedule = $admission->fee_schedule;
                $rows = array_map(fn ($r) => ['period' => $r['period'], 'amount' => $r['hostel_stay'] ? $r['hostel_amount'] : 0, 'applicable' => (bool) $r['hostel_stay']], $schedule['rows']);
                app(FeeScheduleService::class)->setDealSchedule($deal, $schedule, $rows);
                app(FeeScheduleService::class)->allocate($deal);
            }

            return $deal;
        });
    }
}

<?php

namespace App\Http\Controllers\Deal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Deal\DealRequest;
use App\Http\Resources\DealResource;
use App\Models\Activity;
use App\Models\Admission;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\LeadTimeline;
use App\Models\Notification;
use App\Models\Stage;
use App\Services\AdmissionService;
use App\Services\FeeScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DealController extends Controller
{
    public function __construct(private readonly AdmissionService $admissions) {}

    // ── GET /api/deals ────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        if ($request->input('fee_kind') === 'hostel') {
            $this->ensureHostelFeeRows($request);
        }

        $query = Deal::query()
            ->with(['stage', 'pipeline', 'lead.course', 'assignedTo', 'createdBy'])
            ->withSum('payments', 'amount')
            ->withCount('payments');

        $this->branchScope()->constrainVisible($query, $request->user());
        $this->branchScope()->applyAssigneeFilter($query, $request->user());

        $feeKind = $request->input('fee_kind', 'course');
        if (in_array($feeKind, ['course', 'hostel'], true)) {
            $query->where('fee_kind', $feeKind);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhereHas('lead', function ($lq) use ($search) {
                        $lq->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('city', 'like', "%{$search}%");
                    });
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($stageId = $request->input('stage_id')) {
            $query->where('stage_id', $stageId);
        }

        if ($pipelineId = $request->input('pipeline_id')) {
            $query->where('pipeline_id', $pipelineId);
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $allowed = ['created_at', 'title', 'value', 'expected_close_date', 'status', 'probability'];

        if (in_array($sortBy, $allowed)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $deals = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => DealResource::collection($deals),
            'meta' => [
                'total' => $deals->total(),
                'per_page' => $deals->perPage(),
                'current_page' => $deals->currentPage(),
                'last_page' => $deals->lastPage(),
            ],
        ]);
    }

    // ── POST /api/deals ───────────────────────────────────────────────────────

    public function store(DealRequest $request): JsonResponse
    {
        if ($request->input('source_deal_id') && Deal::find($request->input('source_deal_id'))?->fee_periods) {
            abort(422, 'Period fee schedules cannot be handed off or duplicated.');
        }
        $user = $request->user();
        $validated = $request->validated();
        $feeKind = ($validated['fee_kind'] ?? 'course') === 'hostel' ? 'hostel' : 'course';
        $validated['fee_kind'] = $feeKind;
        $branchId = $this->branchScope()->requireWritableBranchId($user, $validated['branch_id'] ?? null);

        if (! empty($validated['pipeline_id']) || ! empty($validated['assigned_to'])) {
            $this->branchScope()->assertAssigneeAndPipeline($validated, $branchId);
        }
        $lead = null;
        if (! empty($validated['lead_id'])) {
            $lead = Lead::query()->find($validated['lead_id']);
            if (! $lead || (int) $lead->branch_id !== $branchId) {
                return response()->json(['message' => 'The selected lead does not belong to this branch.'], 422);
            }

            if ($request->input('source_deal_id') && $lead->course?->period_prices) {
                abort(422, 'Period fee schedules cannot be handed off or duplicated.');
            }
            if ($feeKind === 'hostel') {
                $admission = Admission::query()->where('lead_id', $lead->id)->first();
                if (! $admission || ! $admission->hostel_stay) {
                    return response()->json(['message' => 'This student has not opted for hostel stay.'], 422);
                }
                if ((int) $admission->branch_id !== $branchId) {
                    return response()->json(['message' => 'The selected student does not belong to this branch.'], 422);
                }
                if (Deal::query()->where('lead_id', $lead->id)->where('fee_kind', 'hostel')->exists()) {
                    return response()->json(['message' => 'A hostel fee already exists for this student.'], 422);
                }
                if (! array_key_exists('value', $validated) || $validated['value'] === null || $validated['value'] === '') {
                    $validated['value'] = $admission->hostel_amount;
                }
            } elseif ($lead->status === 'converted' && ! $request->input('source_deal_id')) {
                return response()->json(['message' => 'This lead has already been converted to a deal.'], 422);
            }

            if (empty($validated['assigned_to']) && $lead->assigned_to) {
                $validated['assigned_to'] = $lead->assigned_to;
            }

            if ($feeKind === 'course' && (! array_key_exists('value', $validated) || $validated['value'] === null || $validated['value'] === '')) {
                $fromCourse = $lead->defaultDealValue();
                if ($fromCourse !== null) {
                    $validated['value'] = $fromCourse;
                }
            }
        }

        unset($validated['source_deal_id']);

        $deal = DB::transaction(function () use ($validated, $branchId, $user, $lead, $feeKind) {
            if ($lead) {
                $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
                if ($feeKind === 'course' && $lead->course?->period_prices && $lead->status === 'converted') {
                    abort(422, 'This student already has a course fee schedule.');
                }
            }
            $deal = Deal::create(array_merge($validated, [
                'branch_id' => $branchId,
                'created_by' => $user->id,
            ]));

            if ($feeKind === 'course' && $lead && $lead->status !== 'converted') {
                $oldStatus = $lead->status;
                $lead->update(['status' => 'converted']);

                LeadTimeline::log(
                    $lead,
                    'deal_created',
                    "Converted to deal «{$deal->title}» by {$user->name}",
                    $user->id,
                    ['deal_id' => $deal->id, 'deal_title' => $deal->title]
                );

                LeadTimeline::log(
                    $lead,
                    'status_changed',
                    "Status changed from «{$oldStatus}» to «converted» (deal created)",
                    $user->id,
                    ['from' => $oldStatus, 'to' => 'converted']
                );
            }

            if ($feeKind === 'course' && $lead) {
                $this->admissions->createForConvertedLead($lead->fresh());
                $deal->refresh();
            }
            if ($feeKind === 'hostel' && $lead) {
                $admission = Admission::where('lead_id', $lead->id)->first();
                if ($admission?->fee_schedule) {
                    $schedule = $admission->fee_schedule;
                    $rows = array_map(fn ($r) => ['period' => $r['period'], 'amount' => $r['hostel_stay'] ? $r['hostel_amount'] : 0, 'applicable' => (bool) $r['hostel_stay']], $schedule['rows']);
                    app(FeeScheduleService::class)->setDealSchedule($deal, $schedule, $rows);
                    app(FeeScheduleService::class)->allocate($deal);
                }
            }

            return $deal;
        });

        $sourceDealId = $request->input('source_deal_id');
        if ($sourceDealId) {
            $sourceDeal = Deal::where('id', (int) $sourceDealId)
                ->first();

            if ($sourceDeal && (int) $sourceDeal->branch_id !== $branchId) {
                $deal->delete();

                return response()->json(['message' => 'Pipeline hand-off must stay within the same branch.'], 422);
            }

            // Reject if the source deal was already handed off
            if ($sourceDeal && $sourceDeal->handed_off_at) {
                $deal->delete(); // roll back the deal we just created

                return response()->json([
                    'message' => 'This deal has already been handed off to another pipeline.',
                ], 409);
            }

            // Stamp the source deal as handed off
            DB::table('deals')
                ->where('id', (int) $sourceDealId)
                ->update(['handed_off_at' => now()]);
        }

        return response()->json([
            'data' => new DealResource($deal->load(['stage', 'pipeline', 'lead', 'assignedTo'])),
            'message' => 'Deal created successfully.',
        ], 201);
    }

    // ── GET /api/deals/{deal} ─────────────────────────────────────────────────

    public function show(Request $request, Deal $deal): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $deal->branch_id, $deal->assigned_to);

        $deal->loadCount('payments');
        $deal->loadSum('payments', 'amount');
        $deal->load(['stage', 'pipeline', 'lead', 'assignedTo', 'createdBy', 'activities', 'notes.createdBy']);

        return response()->json(['data' => new DealResource($deal)]);
    }

    // ── PUT /api/deals/{deal} ─────────────────────────────────────────────────

    public function update(DealRequest $request, Deal $deal): JsonResponse
    {
        $user = $request->user();
        $this->branchScope()->assertRecordInScope($user, $deal->branch_id, $deal->assigned_to);
        $validated = $request->validated();
        if ($deal->fee_periods) {
            foreach (['value', 'currency', 'lead_id', 'pipeline_id', 'stage_id', 'status', 'counter_offer_value'] as $field) {
                if (isset($validated[$field]) && (string) $validated[$field] !== (string) $deal->$field && ! (is_numeric($validated[$field]) && is_numeric($deal->$field) && (float) $validated[$field] === (float) $deal->$field)) {
                    throw ValidationException::withMessages([$field => 'Manage period fees from Admission. Payment status is calculated automatically.']);
                }
            }
        }
        unset($validated['branch_id'], $validated['fee_kind']);
        $this->branchScope()->assertAssigneeAndPipeline($validated, (int) $deal->branch_id);
        $oldStatus = $deal->status;
        $newStatus = $validated['status'] ?? $oldStatus;

        // ── Negotiation price sync ─────────────────────────────────────────────
        // Whenever counter_offer_value is present, always mirror it to `value`
        // so the deal amount reflects the negotiated price everywhere.
        $oldCounter = (float) ($deal->counter_offer_value ?? 0);
        $newCounter = isset($validated['counter_offer_value']) && $validated['counter_offer_value'] !== null
                        ? (float) $validated['counter_offer_value']
                        : null;

        if ($newCounter !== null) {
            // Force-write value directly — bypasses dirty-tracking cast quirks
            $validated['value'] = $newCounter;

            // Fire follow-up only when the counter-offer actually changes
            if (abs($newCounter - $oldCounter) > 0.001) {
                $originalQuote = (float) ($deal->original_value ?? $deal->value ?? 0);
                $diff = $newCounter - $originalQuote;
                $direction = $diff < 0 ? 'reduced' : 'increased';

                Activity::create([
                    'branch_id' => $deal->branch_id,
                    'created_by' => $user->id,
                    'assigned_to' => $deal->assigned_to ?? $user->id,
                    'subject_type' => 'deal',
                    'subject_id' => $deal->id,
                    'type' => 'task',
                    'title' => 'Follow up: negotiated price '.$direction
                                        .' — ₹'.number_format($originalQuote, 0)
                                        .' → ₹'.number_format($newCounter, 0),
                    'description' => "Price {$direction} by {$user->name} during negotiation. Confirm updated terms with client and update contract if needed.",
                    'due_at' => now()->addDay()->setTime(10, 0),
                    'priority' => 'high',
                    'is_done' => false,
                ]);
            }
        }

        // ── Guaranteed value write (bypass model cast / dirty-track issues) ───
        // We write the resolved value directly to the DB BEFORE the main update
        // so it is never silently skipped by Eloquent's dirty comparison.
        if ($newCounter !== null) {
            DB::table('deals')->where('id', $deal->id)->update(['value' => $newCounter]);
            $deal->syncOriginal(); // keep the in-memory model consistent
        }

        // When status changes to won/lost via the edit form, auto-move to the
        // matching pipeline stage so the Kanban board reflects the change immediately.
        if ($newStatus !== $oldStatus && in_array($newStatus, ['won', 'lost'])) {
            $colName = $newStatus === 'won' ? 'is_won' : 'is_lost';
            $targetStage = Stage::where('pipeline_id', $deal->pipeline_id)
                ->where($colName, true)
                ->first();
            if ($targetStage) {
                $validated['stage_id'] = $targetStage->id;
            }
            $validated['closed_at'] = now()->toDateString();
        } elseif ($newStatus === 'open' && $oldStatus !== 'open') {
            // Re-opening a deal clears the closed date
            $validated['closed_at'] = null;
        }

        $deal->update($validated);

        // Cascade: deal marked lost → linked lead marked lost
        // (guard in LeadController prevents overwriting a 'converted' lead's status)
        if ($newStatus === 'lost' && $oldStatus !== 'lost' && $deal->lead_id) {
            Lead::where('id', $deal->lead_id)
                ->where('status', '!=', 'lost')
                ->where('status', '!=', 'converted')   // never overwrite converted
                ->update([
                    'status' => 'lost',
                    'lost_reason' => $deal->fresh()->lost_reason ?? null,
                ]);
        }

        return response()->json([
            'data' => new DealResource($deal->fresh(['stage', 'pipeline', 'lead', 'assignedTo'])),
            'message' => 'Deal updated successfully.',
        ]);
    }

    // ── DELETE /api/deals/{deal} ──────────────────────────────────────────────

    public function destroy(Request $request, Deal $deal): JsonResponse
    {
        if ($deal->fee_periods) {
            abort(422, 'Period fee schedules and their history cannot be deleted. Manage prices and hostel stay in Admission.');
        }
        $this->branchScope()->assertRecordInScope($request->user(), $deal->branch_id, $deal->assigned_to);

        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Only managers can delete deals.'], 403);
        }

        $deal->delete();

        return response()->json(['message' => 'Deal deleted successfully.']);
    }

    // ── PATCH /api/deals/{deal}/stage ─────────────────────────────────────────

    public function moveStage(Request $request, Deal $deal): JsonResponse
    {
        if ($deal->fee_periods) {
            abort(422, 'Period fee status is calculated from payments.');
        }
        $this->branchScope()->assertRecordInScope($request->user(), $deal->branch_id, $deal->assigned_to);

        $data = $request->validate([
            'stage_id' => ['required', 'integer', 'exists:stages,id'],
        ]);

        $stage = Stage::findOrFail($data['stage_id']);
        $this->branchScope()->assertStageInPipeline((int) $stage->id, (int) $deal->pipeline_id);

        $updates = ['stage_id' => $stage->id];

        $oldStatus = $deal->status;

        if ($stage->is_won) {
            $updates['status'] = 'won';
            $updates['closed_at'] = now()->toDateString();
        } elseif ($stage->is_lost) {
            $updates['status'] = 'lost';
            $updates['closed_at'] = now()->toDateString();
        } else {
            $updates['status'] = 'open';
            $updates['closed_at'] = null;
        }

        $deal->update($updates);

        // Cascade: deal dragged to lost stage → linked lead marked lost
        if ($stage->is_lost && $oldStatus !== 'lost' && $deal->lead_id) {
            Lead::where('id', $deal->lead_id)
                ->where('status', '!=', 'lost')
                ->update([
                    'status' => 'lost',
                    'lost_reason' => $deal->lost_reason ?? null,
                ]);
        }

        // Notify assignee if different from mover
        if ($deal->assigned_to && $deal->assigned_to !== $request->user()->id) {
            Notification::notify(
                $deal->assigned_to,
                'deal_stage_changed',
                'Deal Stage Updated',
                "{$request->user()->name} moved \"{$deal->title}\" to {$stage->name}",
                ['deal_id' => $deal->id],
                '/deals'
            );
        }

        return response()->json([
            'data' => new DealResource($deal->fresh(['stage', 'pipeline'])),
            'message' => 'Deal moved successfully.',
        ]);
    }

    private function ensureHostelFeeRows(Request $request): void
    {
        $admissions = Admission::query()
            ->where('hostel_stay', true)
            ->with('lead')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('deals')
                    ->whereColumn('deals.lead_id', 'admissions.lead_id')
                    ->where('deals.fee_kind', 'hostel')
                    ->whereNull('deals.deleted_at');
            });

        $this->branchScope()->constrainVisible($admissions, $request->user());

        if ($request->user()->isEmployee()) {
            $admissions->whereHas('lead', fn ($query) => $query->where('assigned_to', $request->user()->id));
        }

        foreach ($admissions->get() as $admission) {
            $this->admissions->syncHostelFee($admission, $request->user());
        }
    }
}

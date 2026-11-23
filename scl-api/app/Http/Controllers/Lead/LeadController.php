<?php

namespace App\Http\Controllers\Lead;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lead\LeadRequest;
use App\Http\Resources\LeadResource;
use App\Http\Resources\LeadTimelineResource;
use App\Models\Activity;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\Department;
use App\Models\Lead;
use App\Models\LeadTimeline;
use App\Models\Notification;
use App\Models\Stage;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\DealStatusService;
use App\Services\LeadAuthorizationService;
use App\Support\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadController extends Controller
{
    public function __construct(
        private readonly LeadAuthorizationService $leadAuth,
        private readonly DealStatusService $dealStatus,
        private readonly AdmissionService $admissions,
    ) {}

    // ── GET /api/leads/department-counts ──────────────────────────────────────

    public function departmentCounts(Request $request): JsonResponse
    {
        if (! $request->user()->isOwner()) {
            return response()->json(['message' => 'You are not allowed to view department lead counts.'], 403);
        }

        // Live leads only (Lead uses SoftDeletes — Eloquent excludes deleted_at automatically).
        $totalQuery = Lead::query();
        $this->branchScope()->constrainVisible($totalQuery, $request->user());
        $total = $totalQuery->count();

        // Count live leads per department via assigned employee. Raw joins skip Eloquent
        // global scopes, so deleted leads/users/departments must be excluded explicitly.
        $countsByDepartment = Lead::query();
        $this->branchScope()->constrainVisible($countsByDepartment, $request->user());
        $countsByDepartment = $countsByDepartment
            ->join('users', function ($join) {
                $join->on('users.id', '=', 'leads.assigned_to')
                    ->whereNull('users.deleted_at');
            })
            ->join('department_user', 'department_user.user_id', '=', 'users.id')
            ->join('departments', function ($join) {
                $join->on('departments.id', '=', 'department_user.department_id')
                    ->whereNull('departments.deleted_at');
            })
            ->groupBy('departments.id')
            ->select('departments.id', DB::raw('COUNT(DISTINCT leads.id) as leads_count'))
            ->pluck('leads_count', 'id');

        $departments = Department::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($dept) => [
                'id' => (int) $dept->id,
                'name' => $dept->name,
                'leads_count' => (int) ($countsByDepartment[$dept->id] ?? 0),
            ])
            ->values();

        return response()->json([
            'data' => [
                'total' => $total,
                'departments' => $departments,
            ],
        ]);
    }

    // ── GET /api/leads/options ────────────────────────────────────────────────

    public function options(Request $request): JsonResponse
    {
        $query = Lead::query()
            ->with('course:id,name,code,price,period_prices,duration_unit,duration_value')
            ->where('status', '!=', 'converted')
            ->orderBy('first_name')
            ->orderBy('last_name');

        $this->branchScope()->constrainVisible($query, $request->user());
        $this->branchScope()->applyAssigneeFilter($query, $request->user());

        if ($request->filled('branch_id')) {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $leads = $query->limit(100)->get();

        return response()->json([
            'data' => $leads->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'full_name' => $lead->full_name,
                'phone' => $lead->phone,
                'status' => $lead->status,
                'course' => $lead->course ? [
                    'id' => $lead->course->id,
                    'name' => $lead->course->name,
                    'code' => $lead->course->code,
                    'period_prices' => $lead->course->period_prices,
                    'duration_unit' => $lead->course->duration_unit,
                    'duration_value' => $lead->course->duration_value,
                    'price' => $lead->course->price !== null ? (float) $lead->course->price : null,
                ] : null,
            ]),
        ]);
    }

    // ── GET /api/leads ────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Lead::query()
            ->with(['stage', 'pipeline', 'assignedTo', 'createdBy', 'course:id,name,code,is_active,branch_id,price,period_prices,duration_unit,duration_value'])
            ->withCount('proposals');

        $this->branchScope()->constrainVisible($query, $request->user());
        $this->branchScope()->applyAssigneeFilter($query, $request->user());

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('state', 'like', "%{$search}%")
                    ->orWhere('pin', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }

        if ($courseId = $request->input('course_id')) {
            $query->where('course_id', (int) $courseId);
        }

        if ($stageId = $request->input('stage_id')) {
            $query->where('stage_id', $stageId);
        }

        if ($pipelineId = $request->input('pipeline_id')) {
            $query->where('pipeline_id', $pipelineId);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->where('lead_date', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->where('lead_date', '<=', $dateTo);
        }

        if ($departmentId = $request->input('department_id')) {
            if (! $request->user()->isOwner()) {
                return response()->json(['message' => 'You are not allowed to filter leads by department.'], 403);
            }

            $departmentExists = Department::where('id', $departmentId)->exists();

            if (! $departmentExists) {
                return response()->json(['message' => 'Invalid department.'], 422);
            }

            $query->whereHas('assignedTo.departments', fn ($q) => $q->where('departments.id', $departmentId));
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $allowed = ['created_at', 'lead_date', 'first_name', 'last_name', 'email', 'status'];

        if (in_array($sortBy, $allowed)) {
            $query->orderBy($sortBy, $sortDir === 'asc' ? 'asc' : 'desc');
        }

        $leads = $query->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => LeadResource::collection($leads),
            'meta' => [
                'total' => $leads->total(),
                'per_page' => $leads->perPage(),
                'current_page' => $leads->currentPage(),
                'last_page' => $leads->lastPage(),
            ],
        ]);
    }

    // ── POST /api/leads ───────────────────────────────────────────────────────

    public function store(LeadRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->leadAuth->canCreate($user)) {
            return response()->json(['message' => 'You are not allowed to create leads.'], 403);
        }

        $payload = $this->leadAuth->attributesForCreate($user, $request->validated());
        $branchId = $this->branchScope()->requireWritableBranchId($user, $payload['branch_id'] ?? null);

        if (! empty($payload['assigned_to']) || ! empty($payload['pipeline_id'])) {
            $this->branchScope()->assertAssigneeAndPipeline($payload, $branchId, ['employee']);
        }

        if (! empty($payload['course_id'])) {
            $this->branchScope()->assertActiveCourseForAssignment((int) $payload['course_id'], $branchId);
        }

        $lead = Lead::create(array_merge($payload, [
            'branch_id' => $branchId,
            'created_by' => $user->id,
            'lead_date' => now()->toDateString(),
        ]));

        // ── Auto-log: lead created ─────────────────────────────────────────────
        LeadTimeline::log(
            $lead,
            'created',
            "Lead created by {$user->name}",
            $user->id,
            ['source' => $lead->source, 'status' => $lead->status]
        );

        return response()->json([
            'data' => new LeadResource($lead->load(['stage', 'pipeline', 'assignedTo', 'course'])),
            'message' => 'Lead created successfully.',
        ], 201);
    }

    // ── GET /api/leads/{lead} ─────────────────────────────────────────────────

    public function show(Request $request, Lead $lead): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $lead->branch_id, $lead->assigned_to);

        $lead->loadCount('proposals');
        $lead->load(['stage', 'pipeline', 'assignedTo', 'createdBy', 'course', 'activities', 'notes.createdBy']);

        return response()->json(['data' => new LeadResource($lead)]);
    }

    // ── PUT /api/leads/{lead} ─────────────────────────────────────────────────

    public function update(LeadRequest $request, Lead $lead): JsonResponse
    {
        $user = $request->user();

        if (! $this->leadAuth->canUpdate($user, $lead)) {
            return response()->json(['message' => 'You are not allowed to edit this lead.'], 403);
        }

        $this->branchScope()->assertRecordInScope($user, $lead->branch_id, $lead->assigned_to);

        $validated = $this->leadAuth->attributesForUpdate($user, $request->validated());
        unset($validated['branch_id']);

        if ($this->leadAuth->deniesDetailEdit($user, $validated)) {
            return response()->json(['message' => 'You are not allowed to edit this lead.'], 403);
        }
        $this->branchScope()->assertAssigneeAndPipeline($validated, (int) $lead->branch_id, ['employee']);
        if (! empty($validated['course_id'])) {
            $courseId = (int) $validated['course_id'];
            if ($courseId !== (int) $lead->course_id) {
                $this->branchScope()->assertActiveCourseForAssignment($courseId, (int) $lead->branch_id);
            } else {
                $this->branchScope()->assertCourseInBranch($courseId, (int) $lead->branch_id);
            }
        }
        $scheduleAt = $validated['schedule_at'] ?? null;
        $remark = isset($validated['remark']) ? trim((string) $validated['remark']) : null;
        if ($remark === '') {
            $remark = null;
        }
        unset($validated['schedule_at'], $validated['remark']);

        // Guard: converted leads are status-locked — only non-status fields may be updated
        if ($lead->status === 'converted' && isset($validated['status']) && $validated['status'] !== 'converted') {
            unset($validated['status']);   // silently strip the status change; other fields still save
        }

        // Detect field-level changes before saving
        $changed = [];
        foreach ($validated as $field => $newVal) {
            $oldVal = $lead->getRawOriginal($field);
            if ((string) $oldVal !== (string) ($newVal ?? '')) {
                $changed[$field] = ['from' => $oldVal, 'to' => $newVal];
            }
        }

        $lead->update($validated);

        if (! empty($changed)) {
            $statusChanged = isset($changed['status']);
            $assignedChanged = isset($changed['assigned_to']);

            if ($statusChanged) {
                LeadTimeline::log(
                    $lead,
                    'status_changed',
                    "Status changed from «{$changed['status']['from']}» to «{$changed['status']['to']}»",
                    $user->id,
                    $changed
                );

                // Call Again / Important — log remark as activity description
                if (in_array($changed['status']['to'], ['ringing', 'important'], true)) {
                    $isRinging = $changed['status']['to'] === 'ringing';
                    $activityType = $isRinging ? 'call' : 'note';
                    $title = $isRinging
                        ? "Call Again — {$lead->full_name}"
                        : "Important — {$lead->full_name}";

                    $activity = Activity::create([
                        'branch_id' => $lead->branch_id,
                        'created_by' => $user->id,
                        'assigned_to' => $lead->assigned_to ?? $user->id,
                        'subject_type' => Lead::class,
                        'subject_id' => $lead->id,
                        'type' => $activityType,
                        'title' => $title,
                        'description' => $remark,
                        'due_at' => null,
                        'priority' => $isRinging ? 'medium' : 'high',
                        'is_done' => false,
                    ]);

                    LeadTimeline::log(
                        $lead,
                        'status_remark',
                        ($isRinging ? 'Call Again' : 'Important').' status set'
                            .($remark ? ": {$remark}" : ''),
                        $user->id,
                        [
                            'activity_id' => $activity->id,
                            'type' => $activityType,
                            'status' => $changed['status']['to'],
                            'remark' => $remark,
                        ]
                    );
                }

                // Cascade "lost" status to all linked deals + move each to its pipeline's lost stage
                if ($changed['status']['to'] === 'lost') {
                    $linkedDeals = Deal::where('lead_id', $lead->id)
                        ->where('status', '!=', 'lost')
                        ->get();

                    foreach ($linkedDeals as $linkedDeal) {
                        $lostStage = Stage::where('pipeline_id', $linkedDeal->pipeline_id)
                            ->where('is_lost', true)
                            ->first();

                        $linkedDeal->update([
                            'status' => 'lost',
                            'stage_id' => $lostStage ? $lostStage->id : $linkedDeal->stage_id,
                            'closed_at' => now(),
                            'lost_reason' => $lead->lost_reason ?? null,
                        ]);
                    }
                }
            } elseif ($assignedChanged) {
                $newAssigneeId = $changed['assigned_to']['to'] ?: null;
                $assigneeName = 'Unassigned';

                if ($newAssigneeId) {
                    $assignee = User::find($newAssigneeId);
                    $assigneeName = $assignee ? $assignee->name : 'Unknown';
                }

                // Cascade assignment to all linked deals
                Deal::where('lead_id', $lead->id)
                    ->update(['assigned_to' => $newAssigneeId]);

                // Cascade assignment to all linked activities
                Activity::where(function ($q) {
                    $q->where('subject_type', 'lead')
                        ->orWhere('subject_type', Lead::class);
                })
                    ->where('subject_id', $lead->id)
                    ->update(['assigned_to' => $newAssigneeId]);

                LeadTimeline::log(
                    $lead,
                    'assigned',
                    "Assigned to {$assigneeName} by {$user->name} (deals & activities updated)",
                    $user->id,
                    array_merge($changed, ['assignee_name' => $assigneeName])
                );

                // Notify new assignee if different from current user
                if ($newAssigneeId && $newAssigneeId !== $user->id) {
                    Notification::notify(
                        $newAssigneeId,
                        'lead_assigned',
                        'Lead Assigned to You',
                        "{$user->name} assigned \"{$lead->full_name}\" to you",
                        ['lead_id' => $lead->id],
                        "/leads/{$lead->id}"
                    );
                }
            } else {
                LeadTimeline::log(
                    $lead,
                    'updated',
                    "Lead details updated by {$user->name} (".implode(', ', array_keys($changed)).')',
                    $user->id,
                    $changed
                );
            }
        }

        // Create or reschedule follow-up / meeting when a due time is provided.
        // Re-selecting the same status updates the latest activity timing.
        if ($scheduleAt && in_array($lead->status, ['followup', 'meeting'], true)) {
            $justSetScheduleStatus = isset($changed['status'])
                && in_array($changed['status']['to'], ['followup', 'meeting'], true);

            $this->upsertScheduledActivity(
                $lead,
                $user,
                $lead->status,
                $scheduleAt,
                $remark,
                $justSetScheduleStatus
            );
        }

        return response()->json([
            'data' => new LeadResource($lead->fresh(['stage', 'pipeline', 'assignedTo', 'course'])),
            'message' => 'Lead updated successfully.',
        ]);
    }

    // ── DELETE /api/leads/{lead} ──────────────────────────────────────────────

    public function destroy(Request $request, Lead $lead): JsonResponse
    {
        if (! $this->leadAuth->canDelete($request->user())) {
            return response()->json(['message' => 'Only managers can delete leads.'], 403);
        }

        $this->branchScope()->assertRecordInScope($request->user(), $lead->branch_id, $lead->assigned_to);

        $user = $request->user();
        $name = $lead->full_name;

        // Log before soft-delete (FK cascade keeps timeline rows)
        LeadTimeline::log(
            $lead,
            'deleted',
            "Lead «{$name}» deleted by {$user->name}",
            $user->id
        );

        $lead->delete();

        return response()->json(['message' => 'Lead deleted successfully.']);
    }

    // ── POST /api/leads/bulk-import ───────────────────────────────────────────

    public function bulkImport(Request $request): JsonResponse
    {
        $request->validate([
            'leads' => ['required', 'array', 'max:500'],
            'leads.*.first_name' => ['required', 'string', 'max:191'],
            'leads.*.email' => ['nullable', 'email', 'max:191'],
            'leads.*.phone' => ['nullable', 'string', 'max:30'],
        ]);

        $userId = $request->user()->id;
        $branchId = $this->branchScope()->requireWritableBranchId(
            $request->user(),
            $request->input('branch_id')
        );
        $now = now();
        $rows = [];
        $seenNormalizedPhones = [];

        foreach ($request->input('leads') as $index => $row) {
            $normalizedPhone = PhoneNormalizer::normalize($row['phone'] ?? null);

            if (! empty($row['assigned_to'])) {
                $this->branchScope()->assertUserInBranch((int) $row['assigned_to'], $branchId, ['employee']);
            }

            if ($normalizedPhone) {
                $duplicateLead = Lead::with('assignedTo:id,name')
                    ->where('phone_normalized', $normalizedPhone)
                    ->where('branch_id', $branchId)
                    ->first();

                if ($duplicateLead) {
                    $assigneeName = $duplicateLead->assignedTo?->name ?? 'Unassigned';

                    return response()->json([
                        'message' => "This lead is already assigned to {$assigneeName}",
                        'errors' => [
                            "leads.{$index}.phone" => ["This lead is already assigned to {$assigneeName}"],
                        ],
                    ], 422);
                }

                if (isset($seenNormalizedPhones[$normalizedPhone])) {
                    return response()->json([
                        'message' => 'Duplicate phone numbers found in the import file.',
                        'errors' => [
                            "leads.{$index}.phone" => ['This phone number is duplicated in the import file.'],
                        ],
                    ], 422);
                }

                $seenNormalizedPhones[$normalizedPhone] = true;
            }

            $rows[] = array_merge($row, [
                'branch_id' => $branchId,
                'phone_normalized' => $normalizedPhone,
                'created_by' => $userId,
                'lead_date' => $now->toDateString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Lead::insert($rows);

        return response()->json([
            'message' => count($rows).' leads imported successfully.',
            'count' => count($rows),
        ], 201);
    }

    // ── GET /api/leads/{lead}/timeline ────────────────────────────────────────

    public function timeline(Request $request, Lead $lead): JsonResponse
    {
        $entries = LeadTimeline::where('lead_id', $lead->id)
            ->with('user')
            ->latest()
            ->get();

        return response()->json([
            'data' => LeadTimelineResource::collection($entries),
        ]);
    }

    // ── POST /api/leads/{lead}/convert ────────────────────────────────────────

    public function convertToDeal(Request $request, Lead $lead): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $lead->branch_id, $lead->assigned_to);

        if ($lead->status === 'converted') {
            return response()->json(['message' => 'This lead has already been converted to a deal.'], 422);
        }

        $request->validate([
            'fee_periods' => ['sometimes', 'array', 'min:1'],
            'fee_periods.*.period' => ['required', 'integer', 'min:1', 'distinct'],
            'fee_periods.*.amount' => ['required', 'numeric', 'min:0'],
            'pipeline_id' => ['required', 'integer', 'exists:pipelines,id'],
            'stage_id' => ['required', 'integer', 'exists:stages,id'],
            'title' => ['nullable', 'string', 'max:191'],
            'value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'in:INR,USD'],
            'payment.amount' => ['nullable', 'numeric', 'min:0.01'],
            'payment.payment_date' => ['nullable', 'required_with:payment.amount', 'date'],
            'payment.payment_mode' => ['nullable', 'required_with:payment.amount', 'in:cash,cheque,bank_transfer,upi,card,aggregator,other'],
            'payment.txn_or_utr_number' => ['nullable', 'string', 'max:100'],
            'payment.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        $oldStatus = $lead->status;
        $dealMarkedWon = false;

        $this->branchScope()->assertPipelineInBranch((int) $request->input('pipeline_id'), (int) $lead->branch_id);
        $this->branchScope()->assertStageInPipeline((int) $request->input('stage_id'), (int) $request->input('pipeline_id'));

        $deal = DB::transaction(function () use ($request, $lead, $user, &$dealMarkedWon) {
            $lead = Lead::whereKey($lead->id)->lockForUpdate()->firstOrFail();
            if ($lead->status === 'converted') {
                abort(422, 'This lead is already admitted.');
            }
            $deal = Deal::create([
                'branch_id' => $lead->branch_id,
                'lead_id' => $lead->id,
                'created_by' => $user->id,
                'assigned_to' => $lead->assigned_to,
                'pipeline_id' => $request->input('pipeline_id'),
                'stage_id' => $request->input('stage_id'),
                'fee_kind' => 'course',
                'title' => $request->input('title', "Deal — {$lead->full_name}"),
                'value' => $request->input('value') !== null && $request->input('value') !== ''
                    ? $request->input('value')
                    : $lead->defaultDealValue(),
                'currency' => $request->input('currency', 'INR') === 'USD' ? 'USD' : 'INR',
                'status' => 'open',
            ]);

            if ($request->filled('payment.amount')) {
                DealPayment::create([
                    'branch_id' => $lead->branch_id,
                    'deal_id' => $deal->id,
                    'created_by' => $user->id,
                    'amount' => $request->input('payment.amount'),
                    'payment_date' => $request->input('payment.payment_date', now()->toDateString()),
                    'payment_mode' => $request->input('payment.payment_mode', 'other'),
                    'txn_or_utr_number' => $request->input('payment.txn_or_utr_number'),
                    'notes' => $request->input('payment.notes'),
                ]);
            }

            $deal->refresh();
            $dealMarkedWon = $this->dealStatus->markWonIfFullyPaid($deal);

            $lead->update(['status' => 'converted']);
            $this->admissions->createForConvertedLead($lead, $request->input('fee_periods'));
            $deal->refresh();
            $dealMarkedWon = $deal->status === 'won';

            return $deal->fresh(['stage', 'pipeline']);
        });

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

        if ($dealMarkedWon) {
            LeadTimeline::log(
                $lead,
                'deal_won',
                "Deal «{$deal->title}» marked as Won — full payment received",
                $user->id,
                ['deal_id' => $deal->id, 'deal_status' => 'won']
            );
        }

        $message = $dealMarkedWon
            ? 'Lead converted to deal and marked as Won — full amount received.'
            : 'Lead converted to deal successfully.';

        return response()->json([
            'data' => $deal,
            'deal_status' => $deal->status,
            'deal_marked_won' => $dealMarkedWon,
            'message' => $message,
        ], 201);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * @param  bool  $forceCreate  True when status just changed to followup/meeting.
     */
    private function upsertScheduledActivity(
        Lead $lead,
        $user,
        string $status,
        string $scheduleAt,
        ?string $remark,
        bool $forceCreate,
    ): void {
        $isMeeting = $status === 'meeting';
        $activityType = $isMeeting ? 'meeting' : 'task';
        $label = $isMeeting ? 'To Visit' : 'Follow-up';
        $title = $isMeeting
            ? "Visit with {$lead->full_name}"
            : "Follow up with {$lead->full_name}";
        $dueAt = Carbon::parse($scheduleAt);

        $subjectTypes = ['lead', Lead::class];

        // Only update the latest open schedule. Never reopen/mutate a completed
        // one by falling back to an older pending activity — create a new one instead.
        $activity = null;
        $wasCompleted = false;
        if (! $forceCreate) {
            $latest = Activity::where('subject_id', $lead->id)
                ->where(function ($q) use ($subjectTypes) {
                    foreach ($subjectTypes as $type) {
                        $q->orWhere('subject_type', $type);
                    }
                })
                ->where('type', $activityType)
                ->whereNotNull('due_at')
                ->orderByDesc('created_at')
                ->first();

            if ($latest && ! $latest->is_done) {
                $activity = $latest;
            } elseif ($latest) {
                $wasCompleted = true;
            }
        }

        if ($activity) {
            $updates = [
                'due_at' => $dueAt,
                'is_done' => false,
                'completed_at' => null,
                'assigned_to' => $lead->assigned_to ?? $user->id,
                'title' => $title,
            ];
            if ($remark !== null) {
                $updates['description'] = $remark;
            }

            $activity->update($updates);
            $activity->refresh();

            $this->retireStaleScheduledActivities($lead, $activityType, $activity->id);

            LeadTimeline::log(
                $lead,
                'activity_scheduled',
                "{$label} rescheduled for ".$activity->due_at->format('d M Y, h:i A'),
                $user->id,
                [
                    'activity_id' => $activity->id,
                    'due_at' => $activity->due_at->toIso8601String(),
                    'type' => $activityType,
                    'remark' => $remark,
                    'rescheduled' => true,
                ]
            );

            $lead->touch();

            return;
        }

        $activity = Activity::create([
            'branch_id' => $lead->branch_id,
            'created_by' => $user->id,
            'assigned_to' => $lead->assigned_to ?? $user->id,
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'type' => $activityType,
            'title' => $title,
            'description' => $remark,
            'due_at' => $dueAt,
            'priority' => 'medium',
            'is_done' => false,
        ]);

        $this->retireStaleScheduledActivities($lead, $activityType, $activity->id);

        $verb = $wasCompleted ? 'rescheduled' : 'scheduled';

        LeadTimeline::log(
            $lead,
            $wasCompleted ? 'activity_scheduled' : 'followup_created',
            "{$label} {$verb} for ".$activity->due_at->format('d M Y, h:i A'),
            $user->id,
            [
                'activity_id' => $activity->id,
                'due_at' => $activity->due_at->toIso8601String(),
                'type' => $activityType,
                'remark' => $remark,
                'rescheduled' => $wasCompleted,
            ]
        );
    }

    /**
     * Soft-delete other open follow-up/meeting activities so list pages show one current schedule.
     */
    private function retireStaleScheduledActivities(Lead $lead, string $activityType, int $keepId): void
    {
        $subjectTypes = ['lead', Lead::class];

        Activity::where('subject_id', $lead->id)
            ->where(function ($q) use ($subjectTypes) {
                foreach ($subjectTypes as $type) {
                    $q->orWhere('subject_type', $type);
                }
            })
            ->where('type', $activityType)
            ->where('is_done', false)
            ->where('id', '!=', $keepId)
            ->delete();
    }
}

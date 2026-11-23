<?php

namespace App\Http\Controllers\SalesTarget;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Models\SalesTarget;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SalesTargetController extends Controller
{
    // ── GET /api/sales-targets?month=YYYY-MM ─────────────────────────────────
    // Managers: all users with actuals; team members: own targets only

    public function index(Request $request): JsonResponse
    {
        $user       = $request->user();
        $canManage  = $user->isAdmin();
        $monthParam = $request->input('month', now()->format('Y-m'));

        [$year, $month] = explode('-', $monthParam);
        $periodStart = Carbon::createFromDate((int)$year, (int)$month, 1)->toDateString();
        $periodEnd   = Carbon::createFromDate((int)$year, (int)$month, 1)->endOfMonth()->toDateString();

        $query = SalesTarget::query()
            ->where('period_start', $periodStart)
            ->with('user:id,name,email');

        $this->branchScope()->constrainVisible($query, $user);

        if (!$canManage) {
            $query->where('user_id', $user->id);
        }

        $rows = $query->get()->map(function (SalesTarget $t) use ($periodStart, $periodEnd) {
            $achieved  = self::calcAchieved((int) $t->user_id, $periodStart, $periodEnd, $t->branch_id ? (int) $t->branch_id : null);
            $received  = self::calcReceived((int) $t->user_id, $periodStart, $periodEnd, $t->branch_id ? (int) $t->branch_id : null);
            return [
                'id'                => $t->id,
                'user'              => $t->user ? ['id' => $t->user->id, 'name' => $t->user->name] : null,
                'target_amount'     => (float) $t->target_amount,
                'receivable_amount' => (float) $t->receivable_amount,
                'received_amount'   => $received,
                'achieved_amount'   => $achieved,
                'notes'             => $t->notes,
                'period_start'      => $t->period_start->toDateString(),
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }

    // ── POST /api/sales-targets — upsert monthly targets for an employee ───────

    public function upsert(Request $request): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Only managers can set targets.'], 403);
        }

        $data = $request->validate([
            'user_id'           => ['required', 'integer', 'exists:users,id'],
            'target_amount'     => ['required', 'numeric', 'min:0'],
            'receivable_amount' => ['required', 'numeric', 'min:0'],
            'period_start'      => ['required', 'date'],
        ]);

        $targetUser = User::query()->findOrFail($data['user_id']);
        $branchId = $this->branchScope()->requireWritableBranchId($request->user(), $targetUser->branch_id);

        if ((int) $targetUser->branch_id !== $branchId) {
            return response()->json(['message' => 'The selected user does not belong to this branch.'], 422);
        }

        if (! $request->user()->isSuperAdmin() && (int) $targetUser->branch_id !== (int) $request->user()->branch_id) {
            return response()->json(['message' => 'You cannot set targets for another branch.'], 403);
        }

        $target = SalesTarget::updateOrCreate(
            [
                'user_id'         => $data['user_id'],
                'period_start'    => $data['period_start'],
            ],
            [
                'branch_id'         => $branchId,
                'target_amount'     => $data['target_amount'],
                'receivable_amount' => $data['receivable_amount'],
            ]
        );

        return response()->json(['data' => $target]);
    }

    // ── PATCH /api/sales-targets/{salesTarget}/received ──────────────────────
    // Admin OR the row's own user can update received_amount

    public function updateReceived(Request $request, SalesTarget $salesTarget): JsonResponse
    {
        $user = $request->user();
        $this->branchScope()->assertRecordInScope($user, $salesTarget->branch_id, $salesTarget->user_id);

        $canManage = $user->isAdmin();

        if (!$canManage && $salesTarget->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->validate([
            'received_amount' => ['required', 'numeric', 'min:0'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        $salesTarget->update($data);

        return response()->json(['data' => $salesTarget->fresh()]);
    }

    // ── GET /api/sales-targets/my-progress?month=YYYY-MM ─────────────────────
    // Returns last 6 months of targets for the authenticated user with achieved_amount

    public function myProgress(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Build array of last 6 months (period_start values)
        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = now()->subMonths($i)->startOfMonth()->toDateString();
        }

        $targets = SalesTarget::query()
            ->where('user_id', $userId)
            ->whereIn('period_start', $months)
            ->orderBy('period_start')
            ->get();

        // Index by period_start for easy lookup
        $indexed = $targets->keyBy(fn($t) => $t->period_start->toDateString());

        $progress = array_map(function (string $periodStart) use ($userId, $indexed) {
            $periodEnd = Carbon::parse($periodStart)->endOfMonth()->toDateString();
            $target    = $indexed->get($periodStart);
            $branchId  = $target?->branch_id ? (int) $target->branch_id : null;
            $achieved  = self::calcAchieved($userId, $periodStart, $periodEnd, $branchId);
            $received  = self::calcReceived($userId, $periodStart, $periodEnd, $branchId);

            return [
                'period_start'      => $periodStart,
                'target_amount'     => $target ? (float) $target->target_amount : 0,
                'receivable_amount' => $target ? (float) $target->receivable_amount : 0,
                'received_amount'   => $received,
                'achieved_amount'   => $achieved,
                'target_id'         => $target?->id,
            ];
        }, $months);

        return response()->json(['data' => $progress]);
    }

    // ── GET /api/sales-targets/{user}/progress ────────────────────────────────
    // Owner/admin: returns last 6 months of targets for any user with achieved_amount

    public function userProgress(Request $request, User $user): JsonResponse
    {
        $authUser = $request->user();

        // Only owners and admins can view another user's progress
        if (! $authUser->isAdmin()) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $authUser->isSuperAdmin() && (int) $user->branch_id !== (int) $authUser->branch_id) {
            return response()->json(['message' => 'You cannot view targets for another branch.'], 403);
        }

        $userId = $user->id;

        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $months[] = now()->subMonths($i)->startOfMonth()->toDateString();
        }

        $targets = SalesTarget::query()
            ->where('user_id', $userId)
            ->whereIn('period_start', $months)
            ->orderBy('period_start')
            ->get();

        $indexed = $targets->keyBy(fn($t) => $t->period_start->toDateString());

        $progress = array_map(function (string $periodStart) use ($userId, $indexed) {
            $periodEnd = Carbon::parse($periodStart)->endOfMonth()->toDateString();
            $target    = $indexed->get($periodStart);
            $branchId  = $target?->branch_id ? (int) $target->branch_id : null;
            $achieved  = self::calcAchieved($userId, $periodStart, $periodEnd, $branchId);
            $received  = self::calcReceived($userId, $periodStart, $periodEnd, $branchId);

            return [
                'period_start'      => $periodStart,
                'target_amount'     => $target ? (float) $target->target_amount : 0,
                'receivable_amount' => $target ? (float) $target->receivable_amount : 0,
                'received_amount'   => $received,
                'achieved_amount'   => $achieved,
                'target_id'         => $target?->id,
            ];
        }, $months);

        return response()->json([
            'data' => $progress,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    // ── Static helper: sum of won deals value for a given user/period ────

    public static function calcAchieved(int $userId, string $from, string $to, ?int $branchId = null): float
    {
        $query = Deal::query()
            ->where('fee_kind', 'course')
            ->where('assigned_to', $userId)
            ->where('status', 'won')
            ->whereBetween('closed_at', [$from, $to]);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return (float) $query->sum('value');
    }

    public static function calcReceived(int $userId, string $from, string $to, ?int $branchId = null): ?float
    {
        $sum = (float) DealPayment::whereHas('deal', function ($q) use ($userId, $branchId) {
            $q->where('fee_kind', 'course')->where('assigned_to', $userId);
            if ($branchId) {
                $q->where('branch_id', $branchId);
            }
        })->whereBetween('payment_date', [$from, $to])->sum('amount');

        return $sum > 0 ? $sum : null;
    }
}

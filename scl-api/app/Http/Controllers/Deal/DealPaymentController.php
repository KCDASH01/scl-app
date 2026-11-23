<?php

namespace App\Http\Controllers\Deal;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\DealPayment;
use App\Services\DealStatusService;
use App\Services\FeeScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DealPaymentController extends Controller
{
    public function __construct(
        private readonly DealStatusService $dealStatus,
    ) {}
    // ── GET /api/deals/{deal}/payments ────────────────────────────────────────
    // List all payments for a deal. Accessible by org members.

    public function index(Request $request, Deal $deal): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $deal->branch_id, $deal->assigned_to);
        $payments = $deal->payments()
            ->with('createdBy:id,name')
            ->orderBy('payment_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $total = (float) $payments->sum('amount');

        return response()->json([
            'data' => $payments->map(fn (DealPayment $p) => $this->toArray($p)),
            'total' => $total,
            'fee_periods' => app(FeeScheduleService::class)->summary($deal),
            'fee_unit' => $deal->fee_unit,
            'fee_total' => $deal->value,
            'history' => DB::table('fee_audits as audit')->leftJoin('users', 'users.id', '=', 'audit.actor_id')->where('audit.deal_id', $deal->id)->select('audit.*', 'users.name as actor_name')->orderByDesc('audit.id')->get()->map(fn ($row) => [
                'id' => $row->id, 'actor_name' => $row->actor_name, 'payment_id' => $row->payment_id, 'action' => $row->action, 'reason' => $row->reason,
                'before' => json_decode($row->before, true), 'after' => json_decode($row->after, true), 'created_at' => Carbon::parse($row->created_at)->toIso8601String(),
            ]),
        ]);
    }

    // ── POST /api/deals/{deal}/payments ───────────────────────────────────────
    // Add a new payment transaction. Open deals only.
    // Accessible by owner/admin OR the deal's assigned user.

    public function store(Request $request, Deal $deal): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $deal->branch_id, $deal->assigned_to);

        $user = $request->user();
        $canManage = $user->isAdmin();

        if (! $canManage && $deal->assigned_to !== $user->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($deal->status !== 'open') {
            return response()->json(['message' => 'Payments can only be added to open deals.'], 422);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'payment_mode' => ['required', 'in:cash,cheque,bank_transfer,upi,card,aggregator,other'],
            'txn_or_utr_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $dealMarkedWon = false;

        $payment = DB::transaction(function () use ($data, $deal, $user, &$dealMarkedWon) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            if ($deal->status !== 'open') {
                abort(422, 'Payments can only be added to open fees.');
            }
            $payment = DealPayment::create(array_merge($data, [
                'branch_id' => $deal->branch_id,
                'deal_id' => $deal->id,
                'created_by' => $user->id,
            ]));

            $deal->refresh();
            app(FeeScheduleService::class)->allocate($deal);
            $dealMarkedWon = $deal->status === 'won' || $this->dealStatus->markWonIfFullyPaid($deal);

            return $payment;
        });

        $payment->refresh()->load('createdBy:id,name');
        $deal->refresh();

        $message = $dealMarkedWon
            ? 'Payment recorded. Deal marked as Won — full amount received.'
            : 'Payment recorded successfully.';

        return response()->json([
            'data' => $this->toArray($payment),
            'deal_status' => $deal->status,
            'deal_marked_won' => $dealMarkedWon,
            'message' => $message,
        ], 201);
    }

    // ── PUT /api/deals/{deal}/payments/{payment} ──────────────────────────────
    // Update payment amount and date. Recalculates deal won/open status.

    public function update(Request $request, Deal $deal, DealPayment $payment): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $deal->branch_id, $deal->assigned_to);

        if ($payment->deal_id !== $deal->id || (int) $payment->branch_id !== (int) $deal->branch_id) {
            abort(404);
        }

        $user = $request->user();
        $canManage = $user->isAdmin();

        if (! $canManage) {
            return response()->json(['message' => 'Only admins and managers can edit payment transactions.'], 403);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'payment_mode' => ['required', 'in:cash,cheque,bank_transfer,upi,card,aggregator,other'],
            'txn_or_utr_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $dealMarkedWon = false;
        $dealReopened = false;

        DB::transaction(function () use ($data, $deal, $payment, $user, &$dealMarkedWon, &$dealReopened) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $payment = DealPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $before = $deal->payments()->orderBy('id')->get()->toArray();
            $oldStatus = $deal->status;
            $payment->update($data);

            $deal->refresh();
            app(FeeScheduleService::class)->allocate($deal, $payment->id);
            $this->dealStatus->markWonIfFullyPaid($deal);
            $this->dealStatus->reopenIfUnderpaid($deal);
            $dealMarkedWon = $oldStatus !== 'won' && $deal->fresh()->status === 'won';
            $dealReopened = $oldStatus === 'won' && $deal->fresh()->status === 'open';
            app(FeeScheduleService::class)->audit($deal, $user, 'payment_corrected', $before, $deal->payments()->orderBy('id')->get()->toArray(), $payment->id, $data['notes'] ?? null);
        });

        $payment->refresh()->load('createdBy:id,name');
        $deal->refresh();

        $message = $dealMarkedWon
            ? 'Payment updated. Deal marked as Won — full amount received.'
            : ($dealReopened
                ? 'Payment updated. Deal reopened because the remaining amount is no longer fully paid.'
                : 'Payment updated successfully.');

        return response()->json([
            'data' => $this->toArray($payment),
            'deal_status' => $deal->status,
            'deal_marked_won' => $dealMarkedWon,
            'deal_reopened' => $dealReopened,
            'message' => $message,
        ]);
    }

    /** @return array<string, mixed> */
    private function toArray(DealPayment $p): array
    {
        return [
            'id' => $p->id,
            'amount' => (float) $p->amount,
            'allocations' => $p->allocations,
            'receipt_snapshot' => $p->receipt_snapshot,
            'payment_date' => $p->payment_date->toDateString(),
            'payment_mode' => $p->payment_mode,
            'txn_or_utr_number' => $p->txn_or_utr_number,
            'notes' => $p->notes,
            'created_by' => $p->createdBy
                ? ['id' => $p->createdBy->id, 'name' => $p->createdBy->name]
                : null,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Controllers\HostelExpense;

use App\Http\Controllers\Controller;
use App\Http\Requests\HostelExpense\HostelExpenseRequest;
use App\Http\Resources\HostelExpenseResource;
use App\Models\Hostel;
use App\Models\HostelExpense;
use Illuminate\Validation\ValidationException;
use App\Support\ReportExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HostelExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->filteredQuery($request);
        $aggregates = (clone $query)->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(amount), 0) as total_amount')->first();

        $expenses = $query->latest('expense_date')->latest('id')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data'    => HostelExpenseResource::collection($expenses),
            'summary' => [
                'count'        => (int) ($aggregates->aggregate_count ?? 0),
                'total_amount' => (float) ($aggregates->total_amount ?? 0),
            ],
            'meta'    => [
                'total'        => $expenses->total(),
                'per_page'     => $expenses->perPage(),
                'current_page' => $expenses->currentPage(),
                'last_page'    => $expenses->lastPage(),
            ],
        ]);
    }

    public function store(HostelExpenseRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $hostel = $this->resolveWritableHostel($request, (int) $validated['hostel_id'], true);
        $this->branchScope()->assertExpenseCategoryInBranch(
            (int) $validated['expense_category_id'],
            (int) $hostel->branch_id,
            true
        );

        $expense = HostelExpense::create([
            'branch_id'           => $hostel->branch_id,
            'hostel_id'           => $hostel->id,
            'expense_category_id' => $validated['expense_category_id'],
            'amount'              => $validated['amount'],
            'expense_date'        => $validated['expense_date'],
            'payment_mode'        => $validated['payment_mode'] ?? null,
            'notes'               => $validated['notes'] ?? null,
            'created_by'          => $request->user()->id,
        ]);

        return response()->json([
            'data'    => new HostelExpenseResource($this->loadExpense($expense)),
            'message' => 'Expense added successfully.',
        ], 201);
    }

    public function show(Request $request, HostelExpense $hostelExpense): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $hostelExpense->branch_id);

        return response()->json(['data' => new HostelExpenseResource($this->loadExpense($hostelExpense))]);
    }

    public function update(HostelExpenseRequest $request, HostelExpense $hostelExpense): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $hostelExpense->branch_id);
        $validated = $request->validated();

        $hostelChanging = (int) $validated['hostel_id'] !== (int) $hostelExpense->hostel_id;
        $categoryChanging = (int) $validated['expense_category_id'] !== (int) $hostelExpense->expense_category_id;

        $hostel = $this->resolveWritableHostel($request, (int) $validated['hostel_id'], $hostelChanging);
        $this->branchScope()->assertExpenseCategoryInBranch(
            (int) $validated['expense_category_id'],
            (int) $hostel->branch_id,
            $categoryChanging
        );

        $hostelExpense->update([
            'branch_id'           => $hostel->branch_id,
            'hostel_id'           => $hostel->id,
            'expense_category_id' => $validated['expense_category_id'],
            'amount'              => $validated['amount'],
            'expense_date'        => $validated['expense_date'],
            'payment_mode'        => $validated['payment_mode'] ?? null,
            'notes'               => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'data'    => new HostelExpenseResource($this->loadExpense($hostelExpense->fresh())),
            'message' => 'Expense updated successfully.',
        ]);
    }

    public function destroy(Request $request, HostelExpense $hostelExpense): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $hostelExpense->branch_id);
        $hostelExpense->delete();

        return response()->json(['message' => 'Expense deleted successfully.']);
    }

    public function export(Request $request): StreamedResponse
    {
        $expenses = $this->filteredQuery($request)
            ->latest('expense_date')
            ->latest('id')
            ->get();

        $rows = $expenses->map(fn (HostelExpense $expense) => [
            $expense->expense_date?->toDateString(),
            $expense->hostel?->name,
            $expense->hostel?->code,
            $expense->category?->name,
            $expense->amount,
            $expense->payment_mode,
            $expense->notes,
            $expense->createdBy?->name,
            $expense->branch?->name,
        ]);

        return ReportExporter::csv(
            'hostel-expenses-'.now()->toDateString().'.csv',
            ['Date', 'Hostel', 'Hostel code', 'Expense type', 'Amount', 'Payment mode', 'Notes', 'Added by', 'Branch'],
            $rows
        );
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = HostelExpense::query()
            ->with([
                'hostel:id,name,code,is_active,branch_id',
                'category:id,name,is_active,branch_id',
                'branch:id,name,code,is_active',
                'createdBy:id,name',
            ]);

        $this->branchScope()->constrainVisible($query, $request->user());

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('notes', 'like', "%{$search}%")
                    ->orWhereHas('hostel', function ($hq) use ($search) {
                        $hq->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    })
                    ->orWhereHas('category', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($hostelId = $request->input('hostel_id')) {
            $query->where('hostel_id', (int) $hostelId);
        }

        if ($categoryId = $request->input('expense_category_id')) {
            $query->where('expense_category_id', (int) $categoryId);
        }

        if ($from = $request->input('date_from')) {
            $query->whereDate('expense_date', '>=', $from);
        }

        if ($to = $request->input('date_to')) {
            $query->whereDate('expense_date', '<=', $to);
        }

        if ($mode = $request->input('payment_mode')) {
            $query->where('payment_mode', $mode);
        }

        return $query;
    }

    private function resolveWritableHostel(Request $request, int $hostelId, bool $requireActive): Hostel
    {
        $hostel = Hostel::query()->find($hostelId);
        if (! $hostel) {
            throw ValidationException::withMessages([
                'hostel_id' => 'The selected hostel was not found.',
            ]);
        }

        $this->branchScope()->requireWritableBranchId($request->user(), $hostel->branch_id);

        return $this->branchScope()->assertHostelInBranch($hostel->id, (int) $hostel->branch_id, $requireActive);
    }

    private function loadExpense(HostelExpense $expense): HostelExpense
    {
        return $expense->load([
            'hostel:id,name,code,is_active,branch_id',
            'category:id,name,is_active,branch_id',
            'branch:id,name,code,is_active',
            'createdBy:id,name',
        ]);
    }
}

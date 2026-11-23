<?php

namespace App\Http\Controllers\ExpenseCategory;

use App\Http\Controllers\Controller;
use App\Http\Requests\ExpenseCategory\ExpenseCategoryRequest;
use App\Http\Resources\ExpenseCategoryResource;
use App\Models\Branch;
use App\Models\ExpenseCategory;
use App\Models\Hostel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ExpenseCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ExpenseCategory::query()
            ->with('branch:id,name,code,is_active')
            ->withCount('expenses');

        $this->branchScope()->constrainVisible($query, $request->user());

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('is_active') && $request->input('is_active') !== '' && $request->input('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $categories = $query->orderBy('name')->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => ExpenseCategoryResource::collection($categories),
            'meta' => [
                'total'        => $categories->total(),
                'per_page'     => $categories->perPage(),
                'current_page' => $categories->currentPage(),
                'last_page'    => $categories->lastPage(),
            ],
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $query = ExpenseCategory::query()
            ->with('branch:id,name,code,is_active')
            ->where('is_active', true)
            ->orderBy('name');

        $this->branchScope()->constrainVisible($query, $request->user());

        if ($hostelId = $request->input('hostel_id')) {
            $hostel = Hostel::query()->find((int) $hostelId);
            if ($hostel) {
                $query->where('branch_id', $hostel->branch_id);
            }
        } elseif ($branchId = $request->input('branch_id')) {
            $query->where('branch_id', (int) $branchId);
        }

        return response()->json(['data' => ExpenseCategoryResource::collection($query->get())]);
    }

    public function store(ExpenseCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $branchId = $this->branchScope()->requireWritableBranchId($request->user(), $validated['branch_id'] ?? null);
        $this->assertActiveBranch($branchId);

        $category = ExpenseCategory::create(array_merge($validated, ['branch_id' => $branchId]));

        return response()->json([
            'data'    => new ExpenseCategoryResource($category->load('branch')->loadCount('expenses')),
            'message' => 'Expense type created successfully.',
        ], 201);
    }

    public function show(Request $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $expenseCategory->branch_id);

        return response()->json([
            'data' => new ExpenseCategoryResource($expenseCategory->load('branch:id,name,code,is_active')->loadCount('expenses')),
        ]);
    }

    public function update(ExpenseCategoryRequest $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $expenseCategory->branch_id);
        $validated = $request->validated();

        if (array_key_exists('branch_id', $validated) && (int) $validated['branch_id'] !== (int) $expenseCategory->branch_id) {
            if ($expenseCategory->hasLinkedRecords()) {
                throw ValidationException::withMessages([
                    'branch_id' => 'This expense type cannot be moved because it already has expenses.',
                ]);
            }
            $this->branchScope()->requireWritableBranchId($request->user(), $validated['branch_id']);
            $this->assertActiveBranch((int) $validated['branch_id']);
        } else {
            unset($validated['branch_id']);
        }

        $expenseCategory->update($validated);

        return response()->json([
            'data'    => new ExpenseCategoryResource($expenseCategory->fresh()->load('branch')->loadCount('expenses')),
            'message' => 'Expense type updated successfully.',
        ]);
    }

    public function destroy(Request $request, ExpenseCategory $expenseCategory): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $expenseCategory->branch_id);

        if ($expenseCategory->hasLinkedRecords()) {
            return response()->json([
                'message' => 'This expense type cannot be deleted because it still has expenses. Deactivate it instead.',
            ], 422);
        }

        $expenseCategory->delete();

        return response()->json(['message' => 'Expense type deleted successfully.']);
    }

    private function assertActiveBranch(int $branchId): Branch
    {
        $branch = Branch::query()->find($branchId);
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'Invalid branch.']);
        }
        if (! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'Inactive branches cannot receive new expense types.',
            ]);
        }

        return $branch;
    }
}

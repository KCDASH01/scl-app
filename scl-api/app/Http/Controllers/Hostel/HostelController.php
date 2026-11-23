<?php

namespace App\Http\Controllers\Hostel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hostel\HostelRequest;
use App\Http\Resources\HostelResource;
use App\Models\Branch;
use App\Models\Hostel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HostelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Hostel::query()
            ->with('branch:id,name,code,is_active')
            ->withCount('expenses');

        $this->branchScope()->constrainVisible($query, $request->user());

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active') && $request->input('is_active') !== '' && $request->input('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $hostels = $query->orderBy('name')->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => HostelResource::collection($hostels),
            'meta' => [
                'total'        => $hostels->total(),
                'per_page'     => $hostels->perPage(),
                'current_page' => $hostels->currentPage(),
                'last_page'    => $hostels->lastPage(),
            ],
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $query = Hostel::query()
            ->with('branch:id,name,code,is_active')
            ->where('is_active', true)
            ->orderBy('name');

        $this->branchScope()->constrainVisible($query, $request->user());

        return response()->json(['data' => HostelResource::collection($query->get())]);
    }

    public function store(HostelRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $branchId = $this->branchScope()->requireWritableBranchId($request->user(), $validated['branch_id'] ?? null);
        $this->assertActiveBranch($branchId);

        $hostel = Hostel::create(array_merge($validated, ['branch_id' => $branchId]));

        return response()->json([
            'data'    => new HostelResource($hostel->load('branch')->loadCount('expenses')),
            'message' => 'Hostel created successfully.',
        ], 201);
    }

    public function show(Request $request, Hostel $hostel): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $hostel->branch_id);

        return response()->json([
            'data' => new HostelResource($hostel->load('branch:id,name,code,is_active')->loadCount('expenses')),
        ]);
    }

    public function update(HostelRequest $request, Hostel $hostel): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $hostel->branch_id);
        $validated = $request->validated();

        if (array_key_exists('branch_id', $validated) && (int) $validated['branch_id'] !== (int) $hostel->branch_id) {
            if ($hostel->hasLinkedRecords()) {
                throw ValidationException::withMessages([
                    'branch_id' => 'This hostel cannot be moved because it already has expenses.',
                ]);
            }
            $this->branchScope()->requireWritableBranchId($request->user(), $validated['branch_id']);
            $this->assertActiveBranch((int) $validated['branch_id']);
        } else {
            unset($validated['branch_id']);
        }

        $hostel->update($validated);

        return response()->json([
            'data'    => new HostelResource($hostel->fresh()->load('branch')->loadCount('expenses')),
            'message' => 'Hostel updated successfully.',
        ]);
    }

    public function destroy(Request $request, Hostel $hostel): JsonResponse
    {
        $this->branchScope()->assertRecordInScope($request->user(), $hostel->branch_id);

        if ($hostel->hasLinkedRecords()) {
            return response()->json([
                'message' => 'This hostel cannot be deleted because it still has expenses. Deactivate it instead.',
            ], 422);
        }

        $hostel->delete();

        return response()->json(['message' => 'Hostel deleted successfully.']);
    }

    private function assertActiveBranch(int $branchId): Branch
    {
        $branch = Branch::query()->find($branchId);
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'Invalid branch.']);
        }
        if (! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch_id' => 'Inactive branches cannot receive new hostels.',
            ]);
        }

        return $branch;
    }
}

<?php

namespace App\Http\Controllers\Department;

use App\Http\Controllers\Controller;
use App\Http\Requests\Department\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    // ── GET /api/departments ──────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $query = Department::query()
                                 ->withCount('users')
                                 ->with('users');
        $this->branchScope()->constrainVisible($query, $request->user());
        $departments = $query->get();

        return response()->json(['data' => DepartmentResource::collection($departments)]);
    }

    // ── POST /api/departments ─────────────────────────────────────────────────

    public function store(DepartmentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $branchId = $this->branchScope()->requireWritableBranchId($request->user(), $validated['branch_id'] ?? null);

        $department = Department::create([
            'branch_id'       => $branchId,
            'name'            => $validated['name'],
            'description'     => $validated['description'] ?? null,
        ]);

        if (! empty($validated['member_ids'])) {
            $memberIds = \App\Models\User::query()
                ->whereIn('id', $validated['member_ids'])
                ->where('branch_id', $branchId)
                ->pluck('id')
                ->all();
            $department->users()->sync($memberIds);
        }

        return response()->json([
            'data'    => new DepartmentResource($department->load('users')),
            'message' => 'Department created successfully.',
        ], 201);
    }

    // ── GET /api/departments/{department} ─────────────────────────────────────

    public function show(Request $request, Department $department): JsonResponse
    {
        return response()->json([
            'data' => new DepartmentResource($department->load('users')),
        ]);
    }

    // ── PUT /api/departments/{department} ─────────────────────────────────────

    public function update(DepartmentRequest $request, Department $department): JsonResponse
    {
        $validated = $request->validated();

        $department->update([
            'name'        => $validated['name'],
            'description' => $validated['description'] ?? $department->description,
        ]);

        if (isset($validated['member_ids'])) {
            $memberIds = \App\Models\User::query()
                ->whereIn('id', $validated['member_ids'])
                ->where('branch_id', $department->branch_id)
                ->pluck('id')
                ->all();
            $department->users()->sync($memberIds);
        }

        return response()->json([
            'data'    => new DepartmentResource($department->fresh('users')),
            'message' => 'Department updated successfully.',
        ]);
    }

    // ── DELETE /api/departments/{department} ──────────────────────────────────

    public function destroy(Request $request, Department $department): JsonResponse
    {
        $department->delete();

        return response()->json(['message' => 'Department deleted successfully.']);
    }
}

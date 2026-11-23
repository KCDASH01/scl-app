<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\BranchRequest;
use App\Http\Resources\BranchResource;
use App\Http\Resources\UserResource;
use App\Models\Branch;
use App\Models\User;
use App\Services\BranchScope;
use App\Services\PipelineSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BranchController extends Controller
{
    public function __construct(
        private readonly BranchScope $branchScope,
        private readonly PipelineSetupService $pipelines,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Branch::query()->withCount(['managers', 'employees']);

        if (! $user->isSuperAdmin()) {
            $query->whereKey($user->branch_id);
        } else {
            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('code', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($request->has('is_active') && $request->input('is_active') !== '' && $request->input('is_active') !== null) {
                $query->where('is_active', $request->boolean('is_active'));
            }
        }

        $branches = $query->orderBy('name')->get();

        return response()->json(['data' => BranchResource::collection($branches)]);
    }

    public function store(BranchRequest $request): JsonResponse
    {
        $branch = DB::transaction(function () use ($request) {
            $branch = Branch::create($request->validated());
            $this->pipelines->createDefaultPipeline($branch->id);

            return $branch;
        });

        return response()->json([
            'data'    => new BranchResource($branch->loadCount(['managers', 'employees'])),
            'message' => 'Branch created successfully.',
        ], 201);
    }

    public function show(Request $request, Branch $branch): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin() && (int) $user->branch_id !== (int) $branch->id) {
            return response()->json(['message' => 'You cannot access another branch.'], 403);
        }

        $branch->load(['managers' => fn ($q) => $q->where('is_active', true)->orderBy('name')]);
        $branch->load(['employees' => fn ($q) => $q->where('is_active', true)->with('manager:id,name,email')->orderBy('name')]);
        $branch->loadCount(['managers', 'employees']);

        return response()->json(['data' => new BranchResource($branch)]);
    }

    public function update(BranchRequest $request, Branch $branch): JsonResponse
    {
        $branch->update($request->validated());

        return response()->json([
            'data'    => new BranchResource($branch->fresh()->loadCount(['managers', 'employees'])),
            'message' => 'Branch updated successfully.',
        ]);
    }

    public function toggleActive(Request $request, Branch $branch): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Only superadmin can change branch status.'], 403);
        }

        $branch->update(['is_active' => ! $branch->is_active]);

        return response()->json([
            'data'    => new BranchResource($branch->fresh()->loadCount(['managers', 'employees'])),
            'message' => $branch->is_active ? 'Branch activated.' : 'Branch deactivated. Managers and employees cannot use the CRM until it is reactivated.',
        ]);
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        if (! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Only superadmin can delete branches.'], 403);
        }

        if ($branch->hasBusinessRecords()) {
            return response()->json([
                'message' => 'This branch cannot be deleted because it still has users or business records. Reassign or remove them first, or deactivate the branch instead.',
            ], 422);
        }

        $branch->delete();

        return response()->json(['message' => 'Branch deleted successfully.']);
    }

    public function managers(Request $request, Branch $branch): JsonResponse
    {
        $user = $request->user();

        if (! $user->isSuperAdmin() && (int) $user->branch_id !== (int) $branch->id) {
            return response()->json(['message' => 'You cannot access another branch.'], 403);
        }

        $managers = User::query()
            ->where('branch_id', $branch->id)
            ->where('role', 'manager')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => UserResource::collection($managers)]);
    }
}

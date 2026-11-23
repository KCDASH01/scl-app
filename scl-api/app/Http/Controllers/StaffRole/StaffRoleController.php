<?php

namespace App\Http\Controllers\StaffRole;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffRole\StaffRoleRequest;
use App\Http\Resources\StaffRoleResource;
use App\Models\StaffRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StaffRoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $request->user()?->canManageTeam()) {
            abort(403, 'You do not have permission to view roles.');
        }

        $roles = StaffRole::query()
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => StaffRoleResource::collection($roles)]);
    }

    public function store(StaffRoleRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $existing = StaffRole::onlyTrashed()->where('name', $validated['name'])->first();

        if ($existing?->isSystem()) {
            throw ValidationException::withMessages([
                'name' => 'Employee and Manager already exist.',
            ]);
        }

        if ($existing) {
            $existing->restore();
            $existing->update(['access' => 'none']);
            $role = $existing;
        } else {
            $role = StaffRole::create([
                'name'   => $validated['name'],
                'access' => 'none',
            ]);
        }

        $role->loadCount('users');

        return response()->json([
            'data'    => new StaffRoleResource($role),
            'message' => 'Role added.',
        ], 201);
    }

    public function update(StaffRoleRequest $request, StaffRole $staffRole): JsonResponse
    {
        if ($staffRole->isSystem()) {
            throw ValidationException::withMessages([
                'name' => 'Employee and Manager cannot be edited.',
            ]);
        }

        $staffRole->update([
            'name'   => $request->validated('name'),
            'access' => 'none',
        ]);
        $staffRole->loadCount('users');

        return response()->json([
            'data'    => new StaffRoleResource($staffRole),
            'message' => 'Role updated.',
        ]);
    }

    public function destroy(Request $request, StaffRole $staffRole): JsonResponse
    {
        if (! $request->user()?->isSuperAdmin()) {
            abort(403, 'Only a superadmin can delete roles.');
        }

        if ($staffRole->isSystem()) {
            throw ValidationException::withMessages([
                'name' => 'Employee and Manager cannot be deleted.',
            ]);
        }

        if ($staffRole->users()->exists()) {
            throw ValidationException::withMessages([
                'name' => 'This role is assigned to team members. Reassign them before deleting it.',
            ]);
        }

        $staffRole->delete();

        return response()->json(['message' => 'Role deleted.']);
    }
}

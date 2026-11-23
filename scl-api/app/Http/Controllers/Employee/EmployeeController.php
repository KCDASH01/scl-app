<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\EmployeeRequest;
use App\Http\Resources\UserResource;
use App\Models\StaffRole;
use App\Models\User;
use App\Services\BranchScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    public function __construct(private readonly BranchScope $branchScope) {}

    public function index(Request $request): JsonResponse
    {
        $auth = $request->user();

        $query = User::query()
            ->with(['branch:id,name,code', 'manager:id,name,email', 'staffRole:id,name,access'])
            ->where('id', '!=', $auth->id)
            ->where('role', '!=', 'superadmin');

        if ($auth->isSuperAdmin()) {
            $requested = $request->input('branch_id');
            if ($requested !== null && $requested !== '') {
                $branchId = strtolower((string) $requested) === 'all' ? null : (int) $requested;
            } else {
                $branchId = $this->branchScope->selectedBranchId($auth, $request);
            }
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        } elseif ($auth->isManager()) {
            $query->where('branch_id', $auth->branch_id)
                  ->where('role', 'employee')
                  ->where('manager_id', $auth->id);
        } else {
            return response()->json(['message' => 'You do not have permission to manage users.'], 403);
        }

        if ($role = $request->input('role')) {
            $query->where('role', $role);
        }

        if ($request->filled('staff_role_id')) {
            $query->where('staff_role_id', (int) $request->input('staff_role_id'));
        }

        if ($request->filled('manager_id')) {
            $query->where('manager_id', (int) $request->input('manager_id'));
        }

        if ($request->has('is_active') && $request->input('is_active') !== '' && $request->input('is_active') !== null) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $employees = $query->orderBy('name')->get();

        return response()->json(['data' => UserResource::collection($employees)]);
    }

    /**
     * Users that can be assigned to records in the current branch.
     */
    public function options(Request $request): JsonResponse
    {
        $auth = $request->user();
        $branchId = $this->branchScope->selectedBranchId($auth, $request);

        if (! $auth->isSuperAdmin() && ! $branchId) {
            return response()->json(['data' => []]);
        }

        $query = User::query()
            ->where('is_active', true)
            ->whereIn('role', ['manager', 'employee', 'admin'])
            ->orderBy('name');

        $role = $request->input('role');
        if (in_array($role, ['employee', 'manager', 'admin'], true)) {
            $query->where('role', $role);
        }

        if ($branchId) {
            $query->where('branch_id', $branchId);
        } elseif ($auth->isSuperAdmin()) {
            $requested = $request->input('branch_id');
            if ($requested && $requested !== 'all') {
                $query->where('branch_id', (int) $requested);
            }
        }

        return response()->json(['data' => UserResource::collection($query->get())]);
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        $auth = $request->user();
        $validated = $request->validated();

        [$branchId, $role, $managerId, $staffRoleId] = $this->resolveAssignment($auth, $validated, null);

        $employee = User::create([
            'name'              => $validated['name'],
            'email'             => $validated['email'],
            'password'          => $validated['password'],
            'phone'             => $validated['phone'] ?? null,
            'avatar'            => $validated['avatar'] ?? null,
            'role'              => $role,
            'staff_role_id'     => $staffRoleId,
            'branch_id'         => $branchId,
            'manager_id'        => $managerId,
            'is_active'         => true,
            'email_verified_at' => now(),
        ]);

        return response()->json([
            'data'    => new UserResource($employee->load(['branch', 'manager', 'staffRole'])),
            'message' => 'Team member added successfully.',
        ], 201);
    }

    public function show(Request $request, User $employee): JsonResponse
    {
        $this->assertCanManage($request->user(), $employee);

        return response()->json([
            'data' => new UserResource($employee->load(['branch', 'manager', 'staffRole', 'departments'])),
        ]);
    }

    public function update(EmployeeRequest $request, User $employee): JsonResponse
    {
        $auth = $request->user();
        $this->assertCanManage($auth, $employee);

        $validated = $request->validated();

        return DB::transaction(function () use ($auth, $employee, $validated) {
            $updates = [];

            foreach (['name', 'email', 'phone', 'avatar'] as $field) {
                if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                    $updates[$field] = $validated[$field];
                }
            }

            if (! empty($validated['password'])) {
                $updates['password'] = $validated['password'];
            }

            $targetRole = $validated['role'] ?? $employee->role;
            $wantsBranch = array_key_exists('branch_id', $validated);
            $wantsRole = array_key_exists('role', $validated);
            $wantsStaffRole = array_key_exists('staff_role_id', $validated) && $validated['staff_role_id'] !== null;
            $wantsManager = array_key_exists('manager_id', $validated);
            $roleUnchanged = ! $wantsRole || ($validated['role'] ?? null) === $employee->role;

            if (($wantsBranch || ($wantsRole && ! $roleUnchanged)) && ! $auth->isSuperAdmin()) {
                throw ValidationException::withMessages([
                    'role' => 'Only superadmin can change role or branch.',
                ]);
            }

            if ($wantsRole || $wantsStaffRole || $wantsBranch || $wantsManager || $targetRole === 'employee') {
                [$branchId, $role, $managerId, $staffRoleId] = $this->resolveAssignment(
                    $auth,
                    array_merge($employee->only(['role', 'branch_id', 'manager_id', 'staff_role_id']), $validated),
                    $employee
                );

                $this->guardRoleAndBranchChange($employee, $role, $branchId);

                $updates['role'] = $role;
                $updates['staff_role_id'] = $staffRoleId;
                $updates['branch_id'] = $branchId;
                $updates['manager_id'] = $managerId;
            }

            if (array_key_exists('is_active', $validated) && $auth->isSuperAdmin()) {
                if ($validated['is_active'] === false) {
                    $this->assertNoDirectReports($employee, 'deactivate');
                }
                $updates['is_active'] = $validated['is_active'];
            }

            if ($updates === []) {
                return response()->json(['message' => 'No changes provided.'], 422);
            }

            $employee->update($updates);

            return response()->json([
                'data'    => new UserResource($employee->fresh(['branch', 'manager', 'staffRole'])),
                'message' => ! empty($validated['password']) && count($updates) === 1
                    ? 'Password updated successfully.'
                    : 'Team member updated successfully.',
            ]);
        });
    }

    public function destroy(Request $request, User $employee): JsonResponse
    {
        $auth = $request->user();
        $this->assertCanManage($auth, $employee);

        if ($employee->role === 'superadmin') {
            return response()->json(['message' => 'Cannot remove the superadmin.'], 422);
        }

        $this->assertNoDirectReports($employee, 'delete');

        if ($employee->assignedLeads()->exists() || $employee->assignedDeals()->exists()) {
            return response()->json([
                'message' => 'This user still has assigned leads or deals. Reassign them before removing the user.',
            ], 422);
        }

        $employee->tokens()->delete();
        $employee->delete();

        return response()->json(['message' => 'Team member removed successfully.']);
    }

    /**
     * @return array{0: int, 1: string, 2: ?int, 3: int}
     */
    private function resolveAssignment(User $auth, array $validated, ?User $existing): array
    {
        $staffRole = $this->selectedStaffRole($validated);

        if ($auth->isManager() && ! $auth->isSuperAdmin()) {
            if ($staffRole && $staffRole->access === 'manager') {
                throw ValidationException::withMessages([
                    'staff_role_id' => 'Select an employee role.',
                ]);
            }

            return [
                (int) $auth->branch_id,
                'employee',
                (int) $auth->id,
                ($staffRole ?? $this->matchingStaffRole($existing, 'employee'))->id,
            ];
        }

        $role = $staffRole
            ? $staffRole->permissionRole()
            : ($validated['role'] ?? $existing?->role ?? 'employee');
        if ($role === 'admin') {
            $role = 'manager';
        }
        if (! in_array($role, User::ASSIGNABLE_ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Role must be manager or employee.']);
        }

        $staffRole ??= $this->matchingStaffRole($existing, $role);

        $branchId = $this->branchScope->requireWritableBranchId(
            $auth,
            $validated['branch_id'] ?? $existing?->branch_id
        );

        $managerId = null;
        if ($role === 'employee') {
            $managerId = (int) ($validated['manager_id'] ?? $existing?->manager_id);
            if ($managerId < 1) {
                throw ValidationException::withMessages([
                    'manager_id' => 'No active manager is available in this branch. Add a manager first.',
                ]);
            }
            if ($existing && $managerId === (int) $existing->id) {
                throw ValidationException::withMessages(['manager_id' => 'A user cannot report to themselves.']);
            }
            $this->branchScope->assertManagerInBranch($managerId, $branchId);
        }

        return [$branchId, $role, $managerId, $staffRole->id];
    }

    private function selectedStaffRole(array $validated): ?StaffRole
    {
        if (empty($validated['staff_role_id'])) {
            return null;
        }

        $staffRole = StaffRole::query()->find((int) $validated['staff_role_id']);
        if (! $staffRole) {
            throw ValidationException::withMessages(['staff_role_id' => 'Select a role.']);
        }

        return $staffRole;
    }

    private function matchingStaffRole(?User $existing, string $access): StaffRole
    {
        if ($existing?->staff_role_id) {
            $current = StaffRole::query()->find($existing->staff_role_id);
            if ($current && ($current->access === $access || ($access === 'employee' && $current->access === 'none'))) {
                return $current;
            }
        }

        return $this->defaultStaffRole($access);
    }

    private function defaultStaffRole(string $access): StaffRole
    {
        $access = $access === 'manager' ? 'manager' : 'employee';
        $name = $access === 'manager' ? 'Manager' : 'Employee';
        $existing = StaffRole::withTrashed()->where('name', $name)->first();

        if ($existing) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        return StaffRole::create([
            'name'   => $name,
            'access' => $access,
        ]);
    }

    private function assertCanManage(User $auth, User $employee): void
    {
        if ($employee->isSuperAdmin()) {
            abort(response()->json(['message' => 'Cannot manage the superadmin from Team.'], 422));
        }

        if ($auth->isSuperAdmin()) {
            return;
        }

        if ($auth->isManager()
            && $employee->role === 'employee'
            && (int) $employee->manager_id === (int) $auth->id
            && (int) $employee->branch_id === (int) $auth->branch_id
        ) {
            return;
        }

        abort(response()->json(['message' => 'You can only manage employees who report to you.'], 403));
    }

    private function guardRoleAndBranchChange(User $employee, string $newRole, int $newBranchId): void
    {
        if ($employee->role === 'manager' && $newRole !== 'manager') {
            $this->assertNoDirectReports($employee, 'demote');
        }

        if ((int) $employee->branch_id !== $newBranchId) {
            if ($employee->directReports()->exists()) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Reassign this manager’s employees before moving them to another branch.',
                ]);
            }

            if ($employee->departments()->exists()) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Remove this user from departments in the current branch before transferring them.',
                ]);
            }

            if ($employee->assignedLeads()->exists() || $employee->assignedDeals()->exists()) {
                throw ValidationException::withMessages([
                    'branch_id' => 'Reassign this user’s leads and deals before changing their branch. Historical records stay in the original branch.',
                ]);
            }
        }
    }

    private function assertNoDirectReports(User $employee, string $action): void
    {
        if ($employee->directReports()->exists()) {
            throw ValidationException::withMessages([
                'role' => "Cannot {$action} this manager until reporting employees are reassigned.",
            ]);
        }
    }
}

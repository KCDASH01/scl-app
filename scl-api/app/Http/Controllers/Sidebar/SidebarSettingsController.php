<?php

namespace App\Http\Controllers\Sidebar;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Services\SidebarSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SidebarSettingsController extends Controller
{
    public function __construct(private readonly SidebarSettingsService $sidebarSettings) {}

    public function show(Request $request): JsonResponse
    {
        if ($request->has('role')) {
            abort_unless($request->user()->isOwner(), 403, 'Only superadmins can manage user sidebar settings.');
            $data = $request->validate([
                'role' => ['required', 'in:manager,employee'],
                'user_id' => ['nullable', 'integer', 'min:1'],
            ]);
            $branchId = $this->branchId($request);
            $user = $this->targetUser($branchId, $data['role'], $data['user_id'] ?? null);

            return $this->scopeResponse($branchId, $data['role'], $user);
        }
        $branchId = $this->branchId($request);
        $stored = $this->sidebarSettings->forBranch($branchId);

        return response()->json([
            'data' => [
                'branch_id' => $branchId,
                'manager' => $stored['manager'],
                'employee' => $stored['employee'],
                'resolved' => $this->sidebarSettings->resolvedFor($request->user()),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        if (! $request->user()->isOwner()) {
            return response()->json(['message' => 'Only superadmins can update sidebar settings.'], 403);
        }

        if ($request->has('role')) {
            $data = $request->validate([
                'branch_id' => ['required', 'integer'],
                'role' => ['required', 'in:manager,employee'],
                'user_id' => ['nullable', 'integer', 'min:1'],
                'map' => ['required_without:reset', 'array'],
                'map.*' => ['boolean'],
                'reset' => ['sometimes', 'boolean'],
                'clear_customizations' => ['sometimes', 'boolean'],
            ]);
            $branchId = $this->branchId($request);
            $user = $this->targetUser($branchId, $data['role'], $data['user_id'] ?? null);
            $reset = $request->boolean('reset');
            $clear = $request->boolean('clear_customizations');
            if (($reset && ! $user) || ($clear && $user) || (! $reset && ! array_key_exists('map', $data))) {
                throw ValidationException::withMessages(['map' => 'Choose a user to reset, or provide settings for the selected scope.']);
            }
            $this->sidebarSettings->saveScope($branchId, $data['role'], $user, $data['map'] ?? [], $reset, $clear);

            return $this->scopeResponse($branchId, $data['role'], $user);
        }

        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'manager' => ['required', 'array'],
            'employee' => ['required', 'array'],
        ]);

        $branchId = $this->branchId($request);
        $stored = $this->sidebarSettings->update(
            $branchId,
            $data['manager'],
            $data['employee']
        );

        return response()->json([
            'data' => [
                'branch_id' => $branchId,
                'manager' => $stored['manager'],
                'employee' => $stored['employee'],
                'resolved' => $this->sidebarSettings->resolvedFor($request->user()),
            ],
        ]);
    }

    private function branchId(Request $request): int
    {
        $id = (int) $request->input('branch_id');
        if ($id < 1 && ! $request->user()->isOwner()) {
            $id = (int) $request->user()->branch_id;
        }

        if (! $request->user()->isOwner() && $id !== (int) $request->user()->branch_id) {
            abort(403, 'You can only view sidebar settings for your own branch.');
        }

        if ($id < 1 || ! Branch::query()->whereKey($id)->exists()) {
            throw ValidationException::withMessages([
                'branch_id' => ['Select a branch.'],
            ]);
        }

        return $id;
    }

    private function targetUser(int $branchId, string $role, ?int $id): ?User
    {
        if ($id === null) {
            return null;
        }
        $user = User::query()->whereKey($id)->where('branch_id', $branchId)
            ->whereIn('role', $role === 'manager' ? ['manager', 'admin'] : ['employee'])->first();
        if (! $user) {
            throw ValidationException::withMessages(['user_id' => 'Select a user from this branch and role.']);
        }

        return $user;
    }

    private function scopeResponse(int $branchId, string $role, ?User $user): JsonResponse
    {
        $defaults = $this->sidebarSettings->forBranch($branchId)[$role];
        $overrides = $this->sidebarSettings->overridesForScope($branchId, $role);
        $users = User::query()->where('branch_id', $branchId)
            ->whereIn('role', $role === 'manager' ? ['manager', 'admin'] : ['employee'])
            ->with('staffRole')->orderBy('name')->get();

        return response()->json(['data' => [
            'branch_id' => $branchId,
            'role' => $role,
            'user_id' => $user?->id,
            'defaults' => $defaults,
            'map' => $user ? $this->sidebarSettings->mapFor($user) : $defaults,
            'has_customization' => $user && $this->sidebarSettings->overrideFor($user) !== null,
            'users' => $users->map(fn (User $member) => [
                'id' => $member->id, 'name' => $member->name, 'email' => $member->email,
                'is_active' => $member->is_active,
                'panel_access' => $member->staffRole?->access !== 'none',
                'has_customization' => isset($overrides[(string) $member->id]),
            ]),
        ]]);
    }
}

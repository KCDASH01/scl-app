<?php

namespace App\Http\Controllers;

use App\Models\User;

class UserController extends Controller
{
    public function index()
    {
        $users = User::query()
            ->with(['branch', 'manager'])
            ->when(request('branch_id'), fn ($q, $id) => $q->where('branch_id', $id))
            ->when(request('role'), fn ($q, $role) => $q->where('role', $role))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $branches = \App\Models\Branch::query()->orderBy('name')->get(['id', 'name', 'code']);

        return view('users.index', compact('users', 'branches'));
    }

    public function show(User $user)
    {
        $user->load(['branch', 'manager']);

        return view('users.show', compact('user'));
    }

    public function toggleActive(User $user)
    {
        if ($user->role === 'superadmin' && $user->is_active) {
            return back()->with('error', 'Cannot deactivate the superadmin directly. Deactivate the company instead.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        $status = $user->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "User {$status} successfully.");
    }
}

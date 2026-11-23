@extends('layouts.admin')

@section('title', 'Users — CRM Admin')
@section('page-title', 'Users')

@section('content')
<form method="GET" class="mb-4 flex flex-wrap gap-3">
    <select name="branch_id" class="border border-gray-200 rounded-lg px-3 py-2 text-sm" onchange="this.form.submit()">
        <option value="">All branches</option>
        @foreach ($branches as $branch)
            <option value="{{ $branch->id }}" @selected(request('branch_id') == $branch->id)>{{ $branch->name }}</option>
        @endforeach
    </select>
    <select name="role" class="border border-gray-200 rounded-lg px-3 py-2 text-sm" onchange="this.form.submit()">
        <option value="">All roles</option>
        @foreach (['superadmin','manager','employee'] as $role)
            <option value="{{ $role }}" @selected(request('role') === $role)>{{ ucfirst($role) }}</option>
        @endforeach
    </select>
</form>
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100">
        <p class="text-sm text-gray-500">{{ $users->total() }} users</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">User</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Role</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Branch</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Reporting Manager</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Joined</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse ($users as $user)
                    <tr class="hover:bg-gray-50/50">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center shrink-0">
                                    <span class="text-gray-600 font-semibold text-xs">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                                </div>
                                <div>
                                    <a href="{{ route('users.show', $user) }}"
                                       class="font-medium text-gray-900 hover:text-indigo-600 text-sm">{{ $user->name }}</a>
                                    <p class="text-xs text-gray-400">{{ $user->email }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600 capitalize">{{ $user->role }}</td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $user->branch?->name ?? ($user->role === 'superadmin' ? 'All branches' : '—') }}</td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $user->manager?->name ?? ($user->role === 'employee' ? '—' : '—') }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $user->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $user->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-400">{{ $user->created_at->format('M j, Y') }}</td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('users.show', $user) }}"
                               class="text-indigo-600 hover:text-indigo-700 text-sm font-medium">View →</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-6 py-10 text-center text-gray-400 text-sm">No users yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($users->hasPages())
        <div class="px-6 py-4 border-t border-gray-100">{{ $users->links() }}</div>
    @endif
</div>
@endsection

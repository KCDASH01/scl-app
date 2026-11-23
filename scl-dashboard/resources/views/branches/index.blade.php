@extends('layouts.admin')

@section('title', 'Branches — CRM Admin')
@section('page-title', 'Branches')

@section('content')
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100">
        <p class="text-sm text-gray-500">{{ $branches->total() }} branches · company-wide oversight</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Branch</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Code</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Managers</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Employees</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse ($branches as $branch)
                    <tr class="hover:bg-gray-50/50">
                        <td class="px-6 py-4">
                            <a href="{{ route('branches.show', $branch) }}" class="font-medium text-gray-900 hover:text-indigo-600 text-sm">{{ $branch->name }}</a>
                            <p class="text-xs text-gray-400">{{ $branch->email ?? $branch->address ?? '—' }}</p>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $branch->code }}</td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $branch->managers_count }}</td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $branch->employees_count }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $branch->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $branch->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('branches.show', $branch) }}" class="text-indigo-600 hover:text-indigo-700 text-sm font-medium">View →</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-6 py-10 text-center text-gray-400 text-sm">No branches yet. Create them in the CRM app as superadmin.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($branches->hasPages())
        <div class="px-6 py-4 border-t border-gray-100">{{ $branches->links() }}</div>
    @endif
</div>
@endsection

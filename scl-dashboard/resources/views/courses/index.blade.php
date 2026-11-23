@extends('layouts.admin')

@section('title', 'Courses — CRM Admin')
@section('page-title', 'Courses')

@section('content')
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-500">{{ $courses->total() }} courses · company-wide oversight</p>
        <form method="GET" class="flex flex-wrap gap-2">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search courses..."
                   class="border border-gray-200 rounded-xl px-3 py-2 text-sm">
            <select name="branch_id" class="border border-gray-200 rounded-xl px-3 py-2 text-sm">
                <option value="">All branches</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected((string) request('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                @endforeach
            </select>
            <button class="text-sm font-semibold px-4 py-2 rounded-xl bg-indigo-600 text-white">Filter</button>
        </form>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-100">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Course</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Code</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Branch</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Duration</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Batches</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse ($courses as $course)
                    <tr class="hover:bg-gray-50/50">
                        <td class="px-6 py-4">
                            <a href="{{ route('courses.show', $course) }}" class="font-medium text-gray-900 hover:text-indigo-600 text-sm">{{ $course->name }}</a>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $course->code }}</td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $course->branch?->name ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-600">
                            {{ $course->duration_value && $course->duration_unit ? $course->duration_value.' '.$course->duration_unit : '—' }}
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">{{ $course->batches_count }}</td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $course->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                                {{ $course->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <a href="{{ route('courses.show', $course) }}" class="text-indigo-600 hover:text-indigo-700 text-sm font-medium">View →</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-6 py-10 text-center text-gray-400 text-sm">No courses added{{ request('branch_id') ? ' for this branch' : '' }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($courses->hasPages())
        <div class="px-6 py-4 border-t border-gray-100">{{ $courses->links() }}</div>
    @endif
</div>
@endsection

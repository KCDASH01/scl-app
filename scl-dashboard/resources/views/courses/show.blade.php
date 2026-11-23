@extends('layouts.admin')

@section('title', $course->name . ' — Course')
@section('page-title', $course->name)

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1">
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-gray-500">Code</dt><dd class="font-medium">{{ $course->code }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Branch</dt><dd>{{ $course->branch?->name ?? '—' }}</dd></div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Duration</dt>
                    <dd>{{ $course->duration_value && $course->duration_unit ? $course->duration_value.' '.$course->duration_unit : '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Status</dt>
                    <dd>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                            {{ $course->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                            {{ $course->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </dd>
                </div>
            </dl>
            @if ($course->description)
                <p class="mt-4 text-sm text-gray-600">{{ $course->description }}</p>
            @endif
        </div>
    </div>
    <div class="lg:col-span-2">
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900">Batches ({{ $course->batches->count() }})</h2>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse ($course->batches as $batch)
                    <div class="px-6 py-3 flex items-center gap-3 text-sm">
                        <div class="flex-1 min-w-0">
                            <p class="font-medium text-gray-900">{{ $batch->name }}</p>
                            <p class="text-xs text-gray-400">
                                {{ $batch->code }}
                                · {{ $batch->start_date?->format('M j, Y') }}
                                @if ($batch->end_date) – {{ $batch->end_date->format('M j, Y') }} @endif
                                @if ($batch->schedule) · {{ $batch->schedule }} @endif
                            </p>
                        </div>
                        <span class="text-xs text-gray-500 capitalize">{{ $batch->status }}</span>
                    </div>
                @empty
                    <div class="px-6 py-8 text-center text-gray-400 text-sm">No batches added for this course.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

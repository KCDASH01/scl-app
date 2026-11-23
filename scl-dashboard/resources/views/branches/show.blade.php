@extends('layouts.admin')

@section('title', $branch->name . ' — Branch')
@section('page-title', $branch->name)

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1">
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between"><dt class="text-gray-500">Code</dt><dd class="font-medium">{{ $branch->code }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Email</dt><dd>{{ $branch->email ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Phone</dt><dd>{{ $branch->phone ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-gray-500">Address</dt><dd class="text-right max-w-[60%]">{{ $branch->address ?? '—' }}</dd></div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Status</dt>
                    <dd>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                            {{ $branch->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                            {{ $branch->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </dd>
                </div>
            </dl>
        </div>
    </div>
    <div class="lg:col-span-2">
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900">Users ({{ $branch->users->count() }})</h2>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse ($branch->users as $user)
                    <div class="px-6 py-3 flex items-center gap-3 text-sm">
                        <div class="flex-1 min-w-0">
                            <a href="{{ route('users.show', $user) }}" class="font-medium text-gray-900 hover:text-indigo-600">{{ $user->name }}</a>
                            <p class="text-xs text-gray-400">{{ $user->email }}</p>
                        </div>
                        <span class="text-xs text-gray-500 capitalize">{{ $user->role }}</span>
                    </div>
                @empty
                    <div class="px-6 py-8 text-center text-gray-400 text-sm">No users in this branch.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

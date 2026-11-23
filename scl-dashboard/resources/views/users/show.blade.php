@extends('layouts.admin')

@section('title', $user->name . ' — CRM Admin')
@section('page-title', $user->name)

@section('header-actions')
    <form method="POST" action="{{ route('users.toggle', $user) }}" class="inline">
        @csrf
        <button type="submit"
                class="text-sm font-medium px-4 py-2 rounded-lg border transition-colors
                       {{ $user->is_active ? 'border-red-300 text-red-600 hover:bg-red-50' : 'border-green-300 text-green-600 hover:bg-green-50' }}">
            {{ $user->is_active ? 'Deactivate User' : 'Activate User' }}
        </button>
    </form>
@endsection

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1">
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <div class="flex items-center gap-4 mb-6">
                <div class="w-14 h-14 rounded-full bg-indigo-100 flex items-center justify-center">
                    <span class="text-indigo-600 font-bold text-xl">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                </div>
                <div>
                    <h2 class="font-semibold text-gray-900">{{ $user->name }}</h2>
                    <p class="text-sm text-gray-400">{{ $user->email }}</p>
                </div>
            </div>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Role</dt>
                    <dd class="capitalize text-gray-900 font-medium">{{ $user->role }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Branch</dt>
                    <dd class="text-gray-900">{{ $user->branch?->name ?? ($user->role === 'superadmin' ? 'All branches' : '—') }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Reporting Manager</dt>
                    <dd class="text-gray-900">{{ $user->manager?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Phone</dt>
                    <dd class="text-gray-900">{{ $user->phone ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Status</dt>
                    <dd>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                            {{ $user->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                            {{ $user->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Joined</dt>
                    <dd class="text-gray-900">{{ $user->created_at->format('M j, Y') }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <div class="lg:col-span-2">
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <h2 class="font-semibold text-gray-900 mb-3">Company</h2>
            <a href="{{ route('company.show') }}" class="font-medium text-indigo-600 hover:text-indigo-700">View company profile →</a>
        </div>
    </div>
</div>
@endsection

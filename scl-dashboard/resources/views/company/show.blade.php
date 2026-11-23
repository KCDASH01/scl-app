@extends('layouts.admin')

@section('title', $company->name . ' — CRM Admin')
@section('page-title', $company->name)

@section('header-actions')
    <form method="POST" action="{{ route('company.toggle') }}" class="inline">
        @csrf
        <button type="submit"
                class="text-sm font-medium px-4 py-2 rounded-lg border transition-colors
                       {{ $company->is_active ? 'border-red-300 text-red-600 hover:bg-red-50' : 'border-green-300 text-green-600 hover:bg-green-50' }}">
            {{ $company->is_active ? 'Deactivate' : 'Activate' }}
        </button>
    </form>
@endsection

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-1 space-y-5">
        <div class="bg-white rounded-2xl border border-gray-200 p-6">
            <h2 class="font-semibold text-gray-900 mb-4">Company info</h2>
            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Email</dt>
                    <dd class="text-gray-900 font-medium">{{ $company->email ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Phone</dt>
                    <dd class="text-gray-900">{{ $company->phone ?? '—' }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Timezone</dt>
                    <dd class="text-gray-900">{{ $company->timezone }}</dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Status</dt>
                    <dd>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                            {{ $company->is_active ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                            {{ $company->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-gray-500">Created</dt>
                    <dd class="text-gray-900">{{ $company->created_at->format('M j, Y') }}</dd>
                </div>
            </dl>
        </div>

        @if ($owner)
            <div class="bg-white rounded-2xl border border-gray-200 p-6">
                <h2 class="font-semibold text-gray-900 mb-4">Superadmin</h2>
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center">
                        <span class="text-indigo-600 font-semibold">{{ strtoupper(substr($owner->name, 0, 1)) }}</span>
                    </div>
                    <div>
                        <p class="font-medium text-gray-900 text-sm">{{ $owner->name }}</p>
                        <p class="text-xs text-gray-400">{{ $owner->email }}</p>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <div class="lg:col-span-2">
        <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h2 class="font-semibold text-gray-900">Users ({{ $users->count() }})</h2>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse ($users as $user)
                    <div class="px-6 py-3 flex items-center gap-3 text-sm">
                        <div class="w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center shrink-0">
                            <span class="text-gray-600 font-semibold text-xs">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <a href="{{ route('users.show', $user) }}"
                               class="font-medium text-gray-900 hover:text-indigo-600 truncate block">{{ $user->name }}</a>
                            <p class="text-xs text-gray-400">{{ $user->email }}</p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="text-xs text-gray-500 capitalize">{{ $user->role }}</span>
                            <span class="w-2 h-2 rounded-full {{ $user->is_active ? 'bg-green-400' : 'bg-red-400' }}"></span>
                        </div>
                    </div>
                @empty
                    <div class="px-6 py-8 text-center text-gray-400 text-sm">No users.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

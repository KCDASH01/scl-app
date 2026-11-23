@extends('layouts.admin')

@section('title', 'Dashboard — CRM Admin')
@section('page-title', 'Dashboard')

@section('content')
@if (! $company)
    <div class="mb-6 rounded-2xl border border-indigo-200 bg-indigo-50 px-5 py-4">
        <p class="text-sm font-semibold text-indigo-900">No company found.</p>
        <p class="text-sm text-indigo-800 mt-1">Run <code class="bg-white px-1 rounded">php artisan db:seed</code> from <code class="bg-white px-1 rounded">scl-api</code>.</p>
    </div>
@endif

<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
    @php
        $cards = [
            ['label' => 'Company',       'value' => $stats['company_name'], 'sub' => $stats['company_active'] ? 'Active' : 'Inactive', 'color' => 'indigo'],
            ['label' => 'Users',         'value' => $stats['total_users'],  'sub' => $stats['active_users'] . ' active', 'color' => 'blue'],
        ];
    @endphp

    @foreach ($cards as $card)
        <div class="bg-white rounded-2xl border border-gray-200 p-5">
            <p class="text-sm font-medium text-gray-500">{{ $card['label'] }}</p>
            <p class="text-2xl font-bold text-gray-900 mt-1">{{ is_numeric($card['value']) ? number_format($card['value']) : $card['value'] }}</p>
            <p class="text-xs text-gray-400 mt-1">{{ $card['sub'] }}</p>
        </div>
    @endforeach
</div>

@if ($company)
<div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
        <h2 class="font-semibold text-gray-900">Recent users</h2>
        <a href="{{ route('users.index') }}" class="text-sm text-indigo-600 hover:text-indigo-700 font-medium">View all →</a>
    </div>
    <div class="divide-y divide-gray-50">
        @forelse ($recentUsers as $user)
            <div class="px-6 py-4 flex items-center gap-4">
                <div class="w-9 h-9 rounded-xl bg-indigo-50 flex items-center justify-center shrink-0">
                    <span class="text-indigo-600 font-semibold text-sm">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                </div>
                <div class="flex-1 min-w-0">
                    <a href="{{ route('users.show', $user) }}" class="font-medium text-gray-900 hover:text-indigo-600 text-sm truncate block">{{ $user->name }}</a>
                    <p class="text-xs text-gray-400">{{ $user->email }} · {{ $user->role }}</p>
                </div>
                <span class="text-xs text-gray-400">{{ $user->created_at->diffForHumans() }}</span>
            </div>
        @empty
            <div class="px-6 py-8 text-center text-gray-400 text-sm">No users yet.</div>
        @endforelse
    </div>
</div>
@endif
@endsection

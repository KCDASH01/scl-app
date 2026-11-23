@extends('layouts.admin')

@section('title', 'Company — CRM Admin')
@section('page-title', 'Company')

@section('content')
<div class="bg-white rounded-2xl border border-indigo-200 p-6">
    <h2 class="font-semibold text-indigo-900 mb-2">Company is not configured</h2>
    <p class="text-sm text-gray-600">From <code>scl-api</code> run:</p>
    <pre class="mt-3 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm">php artisan db:seed</pre>
</div>
@endsection

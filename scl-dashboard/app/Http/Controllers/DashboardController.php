<?php

namespace App\Http\Controllers;

use App\Models\CompanySettings;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        $company = CompanySettings::current();

        $stats = [
            'company_name'   => $company?->name ?? 'Not configured',
            'company_active' => (bool) ($company?->is_active),
            'total_users'    => User::query()->count(),
            'active_users'   => User::query()->where('is_active', true)->count(),
        ];

        $recentUsers = User::query()->orderByDesc('id')->limit(8)->get();

        return view('dashboard.index', compact('stats', 'company', 'recentUsers'));
    }
}

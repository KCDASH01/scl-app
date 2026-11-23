<?php

namespace App\Http\Controllers;

use App\Models\CompanySettings;
use App\Models\User;

class OrganizationController extends Controller
{
    public function show()
    {
        $company = CompanySettings::current();

        if (! $company) {
            return view('company.unconfigured');
        }

        $users = User::query()->orderBy('name')->get();
        $owner = User::query()->where('role', 'superadmin')->orderBy('id')->first();

        return view('company.show', compact('company', 'users', 'owner'));
    }

    public function toggleActive()
    {
        $company = CompanySettings::current();

        if (! $company) {
            return back()->with('error', 'Company is not configured. Run php artisan db:seed from scl-api.');
        }

        $company->update(['is_active' => ! $company->is_active]);

        $status = $company->is_active ? 'activated' : 'deactivated';

        return back()->with('success', "Company {$status} successfully.");
    }
}

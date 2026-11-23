<?php

namespace App\Http\Controllers;

use App\Models\Branch;

class BranchController extends Controller
{
    public function index()
    {
        $branches = Branch::query()
            ->withCount([
                'users',
                'managers',
                'employees',
            ])
            ->orderBy('name')
            ->paginate(25);

        return view('branches.index', compact('branches'));
    }

    public function show(Branch $branch)
    {
        $branch->load(['users' => fn ($q) => $q->orderBy('role')->orderBy('name')]);

        return view('branches.show', compact('branch'));
    }
}

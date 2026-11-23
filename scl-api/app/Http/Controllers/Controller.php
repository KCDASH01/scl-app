<?php

namespace App\Http\Controllers;

use App\Services\BranchScope;

abstract class Controller
{
    protected function branchScope(): BranchScope
    {
        return app(BranchScope::class);
    }
}

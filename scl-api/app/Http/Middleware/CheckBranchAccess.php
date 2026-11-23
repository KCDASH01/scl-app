<?php

namespace App\Http\Middleware;

use App\Services\BranchScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block managers and employees of an inactive (or missing) branch.
 * Superadmin always passes so they can inspect and reactivate.
 */
class CheckBranchAccess
{
    public function __construct(private readonly BranchScope $branchScope) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        try {
            $this->branchScope->assertUserBranchActive($user);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }

        return $next($request);
    }
}

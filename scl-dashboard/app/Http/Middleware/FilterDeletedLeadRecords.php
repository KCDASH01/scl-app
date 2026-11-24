<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FilterDeletedLeadRecords
{
    public function handle(Request $request, Closure $next): Response
    {
        $previous = $request->attributes->get('filter_deleted_lead_records', false);
        $request->attributes->set('filter_deleted_lead_records', true);
        try {
            return $next($request);
        } finally {
            $request->attributes->set('filter_deleted_lead_records', $previous);
        }
    }
}

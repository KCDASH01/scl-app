<?php

namespace App\Http\Middleware;

use App\Support\ActiveLeadVisibility;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FilterDeletedLeadRecords
{
    public function handle(Request $request, Closure $next): Response
    {
        $previous = $request->attributes->get(ActiveLeadVisibility::REQUEST_ATTRIBUTE, false);
        $request->attributes->set(ActiveLeadVisibility::REQUEST_ATTRIBUTE, true);
        try {
            $response = $next($request);
            // CSV exports execute after middleware returns. Keep the same visibility while streaming.
            if ($response instanceof StreamedResponse && $callback = $response->getCallback()) {
                $response->setCallback(function () use ($request, $callback) {
                    $previous = $request->attributes->get(ActiveLeadVisibility::REQUEST_ATTRIBUTE, false);
                    $request->attributes->set(ActiveLeadVisibility::REQUEST_ATTRIBUTE, true);
                    try {
                        $callback();
                    } finally {
                        $request->attributes->set(ActiveLeadVisibility::REQUEST_ATTRIBUTE, $previous);
                    }
                });
            }

            return $response;
        } finally {
            $request->attributes->set(ActiveLeadVisibility::REQUEST_ATTRIBUTE, $previous);
        }
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\AuditLog; // Import AuditLog model
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth; // Import Auth facade
use Symfony\Component\HttpFoundation\Response;

class LogApiRequestsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Log the request before it's processed
        // This ensures a log entry even if the request processing fails later.
        // For more detailed logging (like response status or data changes),
        // you might log after $response = $next($request);

        if (Auth::check() || $request->is('api/login') || $request->is('api/register') || $request->is('api/register/*')) { // Log if user is authenticated or it's a login/register attempt
            AuditLog::create([
                'user_id' => Auth::id(), // Will be null if not authenticated (e.g., during login/register attempt before auth)
                'event' => strtoupper($request->method()) . ' ' . $request->path(),
                'url' => $request->fullUrl(),
                'ip_address' => $request->ip(),
                'user_agent' => substr($request->userAgent() ?? '', 0, 1023), // Ensure user agent is not too long
                // 'auditable_type', 'auditable_id', 'old_values', 'new_values' are typically null for generic request logs
                // and would be set by more specific logging in controllers/services.
            ]);
        }

        return $next($request);
    }
}

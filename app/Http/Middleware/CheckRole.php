<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $role
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next, string $role = null): Response
    {
        // Check if the user is authenticated
        if (!$request->user()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        
        // If no specific role is required, just check if user has any role
        if (!$role && !$request->user()->role_id) {
            return response()->json(['message' => 'Unauthorized. No role assigned.'], 403);
        }
        
        // If a specific role is required, check if the user has that role
        if ($role) {
            // Check for admin roles (admin can access user routes, super_admin can access all routes)
            if ($role === 'user' && $request->user()->isAdmin()) {
                return $next($request);
            }
            
            if ($role === 'admin' && $request->user()->isSuperAdmin()) {
                return $next($request);
            }
            
            // Check exact role match
            if (!$request->user()->hasRole($role)) {
                return response()->json([
                    'message' => 'Unauthorized. Required role: ' . $role,
                ], 403);
            }
        }
        
        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        // Define the list of allowed origins
        $allowedOrigins = config('cors.allowed_origins', []);
        $origin = $request->headers->get('Origin');

        // Log for debugging (remove in production)
        $logDir = storage_path('logs');
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'cors_debug.log';
        
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, 'CORS Debug: ' . json_encode([
            'origin' => $origin,
            'allowed_origins' => $allowedOrigins,
            'method' => $request->method(),
            'url' => $request->url(),
            'timestamp' => date('Y-m-d H:i:s')
        ]) . "\n", FILE_APPEND);

        // Handle preflight OPTIONS requests first
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 200);
            
            // Always add basic CORS headers for OPTIONS requests
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, Application, X-CSRF-TOKEN');
            $response->headers->set('Access-Control-Max-Age', '86400');
            
            // Add origin-specific headers if origin is allowed
            if ($origin && in_array($origin, $allowedOrigins)) {
                $response->headers->set('Access-Control-Allow-Origin', '*');
                $response->headers->set('Access-Control-Allow-Credentials', 'true');
            } else {
                // For debugging: log when origin is not allowed
                \Log::warning('CORS: Origin not allowed for OPTIONS request', [
                    'origin' => $origin,
                    'allowed_origins' => $allowedOrigins
                ]);
            }
            
            return $response;
        }

        // For actual requests, process the request first
        $response = $next($request);

        // Add CORS headers to the response if origin is allowed
        if ($origin && in_array($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Expose-Headers', 'Content-Length, Content-Type');
        }

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleCors
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        
        // Get allowed origins from environment or use defaults
        $envOrigins = env('CORS_ALLOWED_ORIGINS', '');
        $allowedOrigins = !empty($envOrigins) 
            ? explode(',', $envOrigins) 
            : [
                'http://localhost:5173',
                'http://127.0.0.1:5173',
                'http://localhost:3000',
                'http://127.0.0.1:3000',
                'https://mdukuzi-ai.vercel.app',
            ];

        // Handle preflight OPTIONS requests
        if ($request->isMethod('OPTIONS')) {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        // Determine the origin to allow - must be specific when using credentials
        // Cannot use '*' with credentials, so we must check the origin
        $allowedOrigin = null;
        if ($origin) {
            // Check exact match
            if (in_array($origin, $allowedOrigins)) {
                $allowedOrigin = $origin;
            } 
            // Allow any localhost origin in development
            elseif (str_starts_with($origin, 'http://localhost') || str_starts_with($origin, 'http://127.0.0.1')) {
                $allowedOrigin = $origin;
            }
            // Allow Vercel preview deployments (they use *.vercel.app subdomains)
            elseif (str_ends_with($origin, '.vercel.app')) {
                $allowedOrigin = $origin;
            }
        }

        // Set CORS headers
        if ($allowedOrigin) {
            $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        } else {
            // If no origin match but we have an origin header, log it for debugging
            if ($origin && app()->environment('local', 'development')) {
                \Log::debug('CORS: Origin not allowed', ['origin' => $origin, 'allowed' => $allowedOrigins]);
            }
            // Fallback for development - allow all (but without credentials)
            // Only in development mode
            if (app()->environment('local', 'development')) {
                $response->headers->set('Access-Control-Allow-Origin', '*');
            }
        }
        
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept, Origin, X-CSRF-TOKEN');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}


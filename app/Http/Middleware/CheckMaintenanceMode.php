<?php

namespace App\Http\Middleware;

use App\Models\MaintenanceMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip maintenance mode check for API admin routes
        if ($request->is('api/admin/*') || $request->is('api/login')) {
            return $next($request);
        }

        // Skip maintenance mode check for allowed IPs
        if (MaintenanceMode::isIpAllowed($request->ip())) {
            return $next($request);
        }

        // Check if maintenance mode is enabled
        if (MaintenanceMode::isEnabled()) {
            $maintenance = MaintenanceMode::getSettings();
            $message = $maintenance?->message ?? 'We are currently performing scheduled maintenance. Please check back shortly.';

            // Return maintenance mode response
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'maintenance_mode' => true,
                ], 503);
            }

            return response()->view('maintenance', [
                'message' => $message,
            ], 503);
        }

        return $next($request);
    }
}


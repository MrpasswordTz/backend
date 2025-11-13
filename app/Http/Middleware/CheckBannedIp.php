<?php

namespace App\Http\Middleware;

use App\Models\BannedIp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBannedIp
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ipAddress = $request->ip();

        $bannedIp = BannedIp::where('ip_address', $ipAddress)->first();

        if ($bannedIp) {
            return response()->json([
                'success' => false,
                'message' => 'Your IP address has been banned. Reason: ' . ($bannedIp->reason ?? 'No reason provided'),
            ], 403);
        }

        return $next($request);
    }
}


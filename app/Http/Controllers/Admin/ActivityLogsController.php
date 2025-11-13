<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class ActivityLogsController extends Controller
{
    /**
     * Get login history with pagination.
     */
    public function getLoginHistory(Request $request)
    {
        $query = LoginHistory::with('user')->orderBy('created_at', 'desc');

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by user
        if ($request->has('user_id') && $request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        // Search by email
        if ($request->has('search') && $request->search) {
            $query->where('email', 'like', '%' . $request->search . '%');
        }

        $loginHistory = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'login_history' => $loginHistory,
        ]);
    }

    /**
     * Get active sessions.
     * Since we're using Sanctum tokens, we'll track active sessions via LoginHistory
     * that don't have a logged_out_at timestamp (meaning they're still active).
     */
    public function getActiveSessions(Request $request)
    {
        // Get active sessions from LoginHistory (users who logged in but haven't logged out)
        // Also check Sanctum tokens to see which users have active tokens
        $activeLoginHistory = LoginHistory::with('user')
            ->where('status', 'success')
            ->whereNull('logged_out_at')
            ->orderBy('created_at', 'desc')
            ->get();

        // Also get active sessions from database sessions table (if using session-based auth)
        $databaseSessions = [];
        try {
            $dbSessions = DB::table('sessions')
                ->where('last_activity', '>', now()->subMinutes(config('session.lifetime', 120))->timestamp)
                ->orderBy('last_activity', 'desc')
                ->get();

            foreach ($dbSessions as $session) {
                // Skip if we already have this session from LoginHistory
                $exists = $activeLoginHistory->contains(function ($login) use ($session) {
                    return $login->session_id === $session->id;
                });

                if (!$exists && $session->user_id) {
                    $user = \App\Models\User::find($session->user_id);
                    if ($user) {
                        $databaseSessions[] = [
                            'id' => $session->id,
                            'user_id' => $session->user_id,
                            'user' => [
                                'id' => $user->id,
                                'username' => $user->username,
                                'email' => $user->email,
                                'name' => $user->name,
                                'role' => $user->role,
                            ],
                            'ip_address' => $session->ip_address ?? 'N/A',
                            'user_agent' => $session->user_agent ?? 'N/A',
                            'last_activity' => date('Y-m-d H:i:s', $session->last_activity),
                            'login_time' => date('Y-m-d H:i:s', $session->last_activity),
                            'source' => 'database_session',
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Sessions table might not exist or be accessible
            \Log::warning('Could not fetch database sessions: ' . $e->getMessage());
        }

        // Get active Sanctum tokens
        $sanctumTokens = [];
        try {
            $tokens = DB::table('personal_access_tokens')
                ->where(function ($query) {
                    $query->where('expires_at', '>', now())
                          ->orWhereNull('expires_at');
                })
                ->where('tokenable_type', 'App\\Models\\User')
                ->orderBy('last_used_at', 'desc')
                ->get();

            foreach ($tokens as $token) {
                $user = \App\Models\User::find($token->tokenable_id);
                if ($user) {
                    // Check if we already have this user from LoginHistory
                    $exists = $activeLoginHistory->contains(function ($login) use ($user) {
                        return $login->user_id === $user->id;
                    });

                    if (!$exists) {
                        $sanctumTokens[] = [
                            'id' => 'token_' . $token->id,
                            'user_id' => $user->id,
                            'user' => [
                                'id' => $user->id,
                                'username' => $user->username,
                                'email' => $user->email,
                                'name' => $user->name,
                                'role' => $user->role,
                            ],
                            'ip_address' => 'N/A',
                            'user_agent' => 'N/A',
                            'last_activity' => $token->last_used_at ? date('Y-m-d H:i:s', strtotime($token->last_used_at)) : 'Never',
                            'login_time' => $token->created_at ? date('Y-m-d H:i:s', strtotime($token->created_at)) : 'N/A',
                            'source' => 'sanctum_token',
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Personal access tokens table might not exist
            \Log::warning('Could not fetch Sanctum tokens: ' . $e->getMessage());
        }

        // Combine all active sessions
        $allActiveSessions = $activeLoginHistory->map(function ($login) {
            return [
                'id' => $login->session_id ?? 'login_' . $login->id,
                'user_id' => $login->user_id,
                'user' => $login->user ? [
                    'id' => $login->user->id,
                    'username' => $login->user->username,
                    'email' => $login->user->email,
                    'name' => $login->user->name,
                    'role' => $login->user->role,
                ] : null,
                'ip_address' => $login->ip_address ?? 'N/A',
                'user_agent' => $login->user_agent ?? 'N/A',
                'last_activity' => $login->updated_at ? $login->updated_at->format('Y-m-d H:i:s') : $login->created_at->format('Y-m-d H:i:s'),
                'login_time' => $login->created_at->format('Y-m-d H:i:s'),
                'source' => 'login_history',
            ];
        })->concat(collect($databaseSessions))->concat(collect($sanctumTokens));

        // Remove duplicates by user_id (keep the most recent)
        $uniqueSessions = $allActiveSessions->unique('user_id')->values();

        return response()->json([
            'success' => true,
            'active_sessions' => $uniqueSessions,
            'total' => $uniqueSessions->count(),
        ]);
    }

    /**
     * Force logout a user session.
     * Handles different session ID formats: login_history ID, session ID, or token ID.
     */
    public function forceLogout(Request $request, $sessionId)
    {
        try {
            $user = Auth::user();
            $loggedOut = false;
            $targetUserId = null;

            // Check if it's a login history ID (format: login_123)
            if (strpos($sessionId, 'login_') === 0) {
                $loginId = str_replace('login_', '', $sessionId);
                $loginHistory = LoginHistory::find($loginId);
                
                if ($loginHistory) {
                    $loginHistory->logged_out_at = now();
                    $loginHistory->save();
                    $targetUserId = $loginHistory->user_id;
                    $loggedOut = true;
                }
            } 
            // Check if it's a token ID (format: token_123)
            else if (strpos($sessionId, 'token_') === 0) {
                $tokenId = str_replace('token_', '', $sessionId);
                $token = DB::table('personal_access_tokens')->where('id', $tokenId)->first();
                
                if ($token) {
                    // Delete the token
                    DB::table('personal_access_tokens')->where('id', $tokenId)->delete();
                    $targetUserId = $token->tokenable_id;
                    $loggedOut = true;
                }
            } 
            // Otherwise, treat it as a session ID
            else {
                // Try to find in login history by session_id
                $loginHistory = LoginHistory::where('session_id', $sessionId)
                    ->whereNull('logged_out_at')
                    ->first();
                
                if ($loginHistory) {
                    $loginHistory->logged_out_at = now();
                    $loginHistory->save();
                    $targetUserId = $loginHistory->user_id;
                    $loggedOut = true;
                }
                
                // Also try to delete from sessions table
                try {
                    $session = DB::table('sessions')->where('id', $sessionId)->first();
                    if ($session) {
                        if (!$targetUserId) {
                            $targetUserId = $session->user_id;
                        }
                        DB::table('sessions')->where('id', $sessionId)->delete();
                        $loggedOut = true;
                    }
                } catch (\Exception $e) {
                    // Sessions table might not exist
                }

                // Also delete all Sanctum tokens for this user if we found a user_id
                if ($targetUserId) {
                    try {
                        DB::table('personal_access_tokens')
                            ->where('tokenable_id', $targetUserId)
                            ->where('tokenable_type', 'App\\Models\\User')
                            ->delete();
                    } catch (\Exception $e) {
                        // Personal access tokens table might not exist
                    }
                }
            }

            if (!$loggedOut) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session not found.',
                ], 404);
            }

            // Log action
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'force_logout_session',
                'description' => "Force logged out session: {$sessionId}" . ($targetUserId ? " (User ID: {$targetUserId})" : ''),
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Session logged out successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to logout session.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user activity (from audit logs).
     */
    public function getUserActivity(Request $request)
    {
        $query = AuditLog::with('user')->orderBy('created_at', 'desc');

        // Filter by user
        if ($request->has('user_id') && $request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by action
        if ($request->has('action') && $request->action) {
            $query->where('action', 'like', '%' . $request->action . '%');
        }

        // Search
        if ($request->has('search') && $request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('action', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%')
                  ->orWhere('ip_address', 'like', '%' . $request->search . '%');
            });
        }

        // Date range filter
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $activity = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'activity' => $activity,
        ]);
    }

    /**
     * Get failed login attempts.
     */
    public function getFailedLoginAttempts(Request $request)
    {
        $query = LoginHistory::with('user')
            ->where('status', 'failed')
            ->orWhere('status', 'blocked')
            ->orderBy('created_at', 'desc');

        // Filter by email
        if ($request->has('email') && $request->email) {
            $query->where('email', 'like', '%' . $request->email . '%');
        }

        // Date range filter
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $failedAttempts = $query->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'failed_attempts' => $failedAttempts,
        ]);
    }
}

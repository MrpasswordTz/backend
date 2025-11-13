<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\AuditLog;
use App\Models\FailedLoginAttempt;
use App\Models\SecuritySetting;
use App\Models\LoginHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:255|unique:users',
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'username' => $request->username,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => 'user',
        ]);

        // Log the registration
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'user_registered',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => ['username' => $user->username, 'email' => $user->email],
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Login user.
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $email = $request->email;
        $ipAddress = $request->ip();

        // Check for existing failed login attempts
        $failedAttempt = FailedLoginAttempt::where('email', $email)
            ->where('ip_address', $ipAddress)
            ->first();

        // Get security settings
        $maxAttempts = (int) SecuritySetting::getValue('failed_login_attempts_limit', 5);
        $lockoutDuration = (int) SecuritySetting::getValue('failed_login_lockout_duration', 15);

        // Check if account is locked
        if ($failedAttempt && $failedAttempt->isLocked()) {
            $remainingMinutes = $failedAttempt->getRemainingLockoutTime();
            
            // Log locked account attempt to login history
            LoginHistory::create([
                'user_id' => null,
                'email' => $email,
                'ip_address' => $ipAddress,
                'user_agent' => $request->userAgent(),
                'status' => 'blocked',
                'failure_reason' => 'Account locked due to too many failed login attempts',
            ]);
            
            // Log locked account attempt
            AuditLog::create([
                'user_id' => null,
                'action' => 'login_blocked_locked',
                'ip_address' => $ipAddress,
                'user_agent' => $request->userAgent(),
                'details' => [
                    'email' => $email,
                    'reason' => 'Account locked due to too many failed login attempts',
                    'remaining_minutes' => $remainingMinutes,
                ],
            ]);

            return response()->json([
                'success' => false,
                'message' => "Account is temporarily locked due to too many failed login attempts. Please try again in {$remainingMinutes} minutes.",
                'locked' => true,
                'remaining_minutes' => $remainingMinutes,
            ], 423); // 423 Locked
        }

        $user = User::where('email', $email)->first();

        // Check credentials
        if (!$user || !Hash::check($request->password, $user->password)) {
            // Record failed login attempt
            if ($failedAttempt) {
                $failedAttempt->attempt_count += 1;
                $failedAttempt->attempted_at = now();
                $failedAttempt->save();
            } else {
                $failedAttempt = FailedLoginAttempt::recordFailedAttempt($email, $ipAddress);
            }

            // Check if we should lock the account
            if ($failedAttempt->attempt_count >= $maxAttempts) {
                $failedAttempt->lock($lockoutDuration);
                
                // Log account lockout
                AuditLog::create([
                    'user_id' => $user?->id,
                    'action' => 'account_locked',
                    'ip_address' => $ipAddress,
                    'user_agent' => $request->userAgent(),
                    'details' => [
                        'email' => $email,
                        'attempt_count' => $failedAttempt->attempt_count,
                        'lockout_duration_minutes' => $lockoutDuration,
                    ],
                ]);

                return response()->json([
                    'success' => false,
                    'message' => "Too many failed login attempts. Your account has been locked for {$lockoutDuration} minutes.",
                    'locked' => true,
                    'remaining_minutes' => $lockoutDuration,
                ], 423); // 423 Locked
            }

            // Log failed login attempt to login history
            LoginHistory::create([
                'user_id' => $user?->id,
                'email' => $email,
                'ip_address' => $ipAddress,
                'user_agent' => $request->userAgent(),
                'status' => 'failed',
                'failure_reason' => 'Invalid credentials',
            ]);
            
            // Log failed login attempt
            AuditLog::create([
                'user_id' => null,
                'action' => 'login_failed',
                'ip_address' => $ipAddress,
                'user_agent' => $request->userAgent(),
                'details' => [
                    'email' => $email,
                    'attempt_count' => $failedAttempt->attempt_count,
                    'max_attempts' => $maxAttempts,
                ],
            ]);

            $remainingAttempts = $maxAttempts - $failedAttempt->attempt_count;
            return response()->json([
                'success' => false,
                'message' => "Invalid credentials. {$remainingAttempts} attempt(s) remaining before account lockout.",
                'attempts_remaining' => $remainingAttempts,
            ], 401);
        }

        // Successful login - reset failed attempts
        if ($failedAttempt) {
            $failedAttempt->resetAttempts();
        }

        // Get session ID from request (if using session-based auth)
        // For API routes using Sanctum, session_id will be null
        $sessionId = null;
        try {
            if ($request->hasSession()) {
                $sessionId = $request->session()->getId();
            }
        } catch (\Exception $e) {
            // Session not available (API route)
        }

        // Log successful login to login history
        LoginHistory::create([
            'user_id' => $user->id,
            'email' => $email,
            'ip_address' => $ipAddress,
            'user_agent' => $request->userAgent(),
            'status' => 'success',
            'session_id' => $sessionId,
        ]);

        // Log successful login
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'login_success',
            'ip_address' => $ipAddress,
            'user_agent' => $request->userAgent(),
            'details' => ['username' => $user->username],
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Logout user.
     */
    public function logout(Request $request)
    {
        $user = $request->user();
        $sessionId = null;
        
        // Get session ID if available
        try {
            if ($request->hasSession()) {
                $sessionId = $request->session()->getId();
            }
        } catch (\Exception $e) {
            // Session not available (API route)
        }
        
        // Update login history with logout time
        // For API routes, update the most recent login without logout time
        if ($user) {
            if ($sessionId) {
                LoginHistory::where('user_id', $user->id)
                    ->where('session_id', $sessionId)
                    ->whereNull('logged_out_at')
                    ->update(['logged_out_at' => now()]);
            } else {
                // For API routes, update the most recent login
                LoginHistory::where('user_id', $user->id)
                    ->whereNull('logged_out_at')
                    ->orderBy('created_at', 'desc')
                    ->limit(1)
                    ->update(['logged_out_at' => now()]);
            }
        }
        
        // Log logout
        if ($user) {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'logout',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }

        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    /**
     * Get authenticated user.
     */
    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'user' => $request->user(),
        ]);
    }
}


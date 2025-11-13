<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\AuditLog;
use App\Models\BannedIp;
use App\Models\ChatHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminController extends Controller
{
    // AdminMiddleware is applied in routes

    /**
     * Get dashboard statistics.
     */
    public function dashboardStats(Request $request)
    {
        $totalUsers = User::count();
        $totalAdmins = User::where('role', 'admin')->count();
        $totalNormalUsers = User::where('role', 'user')->count();
        $totalChats = ChatHistory::count();
        $totalAuditLogs = AuditLog::count();
        $bannedIps = BannedIp::count();

        // Get user registration stats for the last 30 days
        $userRegistrations = User::where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        // Get chat stats for the last 30 days
        $chatStats = ChatHistory::where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        // Get user role distribution
        $roleDistribution = User::selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->get();

        return response()->json([
            'success' => true,
            'stats' => [
                'total_users' => $totalUsers,
                'total_admins' => $totalAdmins,
                'total_normal_users' => $totalNormalUsers,
                'total_chats' => $totalChats,
                'total_audit_logs' => $totalAuditLogs,
                'banned_ips' => $bannedIps,
                'user_registrations' => $userRegistrations,
                'chat_stats' => $chatStats,
                'role_distribution' => $roleDistribution,
            ],
        ]);
    }

    /**
     * Get all users.
     */
    public function getUsers(Request $request)
    {
        $users = User::withCount(['chatHistory', 'auditLogs'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'users' => $users,
        ]);
    }

    /**
     * Get a specific user.
     */
    public function getUser($id)
    {
        $user = User::withCount(['chatHistory', 'auditLogs'])
            ->with('chatHistory')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'user' => $user,
        ]);
    }

    /**
     * Create a new user.
     */
    public function createUser(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'username' => 'required|string|max:255|unique:users',
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:admin,user',
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
            'role' => $request->role,
        ]);

        // Log the action
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'admin_create_user',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => [
                'created_user_id' => $user->id,
                'created_user_username' => $user->username,
                'created_user_role' => $user->role,
            ],
        ]);

        return response()->json([
            'success' => true,
            'user' => $user,
        ], 201);
    }

    /**
     * Update a user.
     */
    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'username' => 'sometimes|required|string|max:255|unique:users,username,' . $id,
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|string|email|max:255|unique:users,email,' . $id,
            'password' => 'sometimes|required|string|min:8',
            'role' => 'sometimes|required|in:admin,user',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $oldData = $user->toArray();

        if ($request->has('username')) {
            $user->username = $request->username;
        }
        if ($request->has('name')) {
            $user->name = $request->name;
        }
        if ($request->has('email')) {
            $user->email = $request->email;
        }
        if ($request->has('password')) {
            $user->password = Hash::make($request->password);
        }
        if ($request->has('role')) {
            $user->role = $request->role;
        }

        $user->save();

        // Log the action
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'admin_update_user',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => [
                'updated_user_id' => $user->id,
                'old_data' => $oldData,
                'new_data' => $user->toArray(),
            ],
        ]);

        return response()->json([
            'success' => true,
            'user' => $user,
        ]);
    }

    /**
     * Delete a user.
     */
    public function deleteUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        // Prevent admin from deleting themselves
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account',
            ], 400);
        }

        $userData = $user->toArray();
        $user->delete();

        // Log the action
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'admin_delete_user',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => [
                'deleted_user_id' => $userData['id'],
                'deleted_user_username' => $userData['username'],
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Get audit logs.
     */
    public function getAuditLogs(Request $request)
    {
        $perPage = $request->get('per_page', 20);
        $perPage = min(max((int)$perPage, 1), 100); // Limit between 1 and 100
        
        $logs = AuditLog::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'logs' => $logs,
        ]);
    }

    /**
     * Delete all audit logs.
     */
    public function deleteAllAuditLogs(Request $request)
    {
        // Count logs before deletion for response
        $logCount = AuditLog::count();

        // Log the action before deleting (this log will also be deleted)
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'admin_delete_all_audit_logs',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => [
                'deleted_count' => $logCount,
                'deleted_by' => $request->user()->username,
            ],
        ]);

        // Delete all audit logs
        AuditLog::truncate();

        // Note: The log entry above will also be deleted, but that's expected
        // External logging systems (if any) will have captured this action

        return response()->json([
            'success' => true,
            'message' => 'All audit logs deleted successfully',
            'deleted_count' => $logCount,
        ]);
    }

    /**
     * Get banned IPs.
     */
    public function getBannedIps(Request $request)
    {
        $bannedIps = BannedIp::with('bannedBy')
            ->orderBy('banned_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'banned_ips' => $bannedIps,
        ]);
    }

    /**
     * Ban an IP address.
     */
    public function banIp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ip_address' => 'required|ip',
            'reason' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $bannedIp = BannedIp::firstOrCreate(
            ['ip_address' => $request->ip_address],
            [
                'reason' => $request->reason,
                'banned_by' => $request->user()->id,
                'banned_at' => now(),
            ]
        );

        // Log the action
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'admin_ban_ip',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => [
                'banned_ip' => $request->ip_address,
                'reason' => $request->reason,
            ],
        ]);

        return response()->json([
            'success' => true,
            'banned_ip' => $bannedIp,
        ]);
    }

    /**
     * Unban an IP address.
     */
    public function unbanIp(Request $request, $id)
    {
        $bannedIp = BannedIp::findOrFail($id);
        $ipAddress = $bannedIp->ip_address;
        $bannedIp->delete();

        // Log the action
        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'admin_unban_ip',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => [
                'unbanned_ip' => $ipAddress,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'IP unbanned successfully',
        ]);
    }
}


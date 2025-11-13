<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\SystemHealthController;
use App\Http\Controllers\SecuritySettingsController;
use App\Http\Controllers\MaintenanceModeController;
use App\Http\Controllers\CacheManagementController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Admin\ContactSubmissionController;
use App\Http\Controllers\Admin\AdminChatController;
use App\Http\Controllers\Admin\ApiManagementController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\ActivityLogsController;
use Illuminate\Support\Facades\Route;

// API info endpoint
Route::get('/', function () {
    return response()->json([
        'success' => true,
        'message' => 'Mdukuzi AI API',
        'version' => '1.0.0',
        'endpoints' => [
            'auth' => [
                'POST /api/register' => 'Register a new user',
                'POST /api/login' => 'Login user',
                'POST /api/logout' => 'Logout user (requires auth)',
                'GET /api/me' => 'Get authenticated user (requires auth)',
            ],
            'contact' => [
                'POST /api/contact' => 'Submit contact form (public)',
            ],
            'chat' => [
                'POST /api/chat/message' => 'Send chat message (requires auth)',
                'GET /api/chat/history' => 'Get chat history (requires auth)',
                'GET /api/chat/sessions' => 'Get chat sessions (requires auth)',
                'DELETE /api/chat/history/{id?}' => 'Delete chat history (requires auth)',
            ],
            'admin' => [
                'GET /api/admin/dashboard/stats' => 'Get dashboard stats (requires admin)',
                'GET /api/admin/users' => 'Get all users (requires admin)',
                'POST /api/admin/users' => 'Create user (requires admin)',
                'GET /api/admin/users/{id}' => 'Get user (requires admin)',
                'PUT /api/admin/users/{id}' => 'Update user (requires admin)',
                'DELETE /api/admin/users/{id}' => 'Delete user (requires admin)',
                'GET /api/admin/audit-logs' => 'Get audit logs (requires admin)',
                'DELETE /api/admin/audit-logs' => 'Delete all audit logs (requires admin)',
                'GET /api/admin/banned-ips' => 'Get banned IPs (requires admin)',
                'POST /api/admin/banned-ips' => 'Ban IP (requires admin)',
                'DELETE /api/admin/banned-ips/{id}' => 'Unban IP (requires admin)',
                'GET /api/admin/contact-submissions' => 'Get contact submissions (requires admin)',
                'GET /api/admin/contact-submissions/{id}' => 'Get contact submission (requires admin)',
                'PUT /api/admin/contact-submissions/{id}/read' => 'Mark submission as read/unread (requires admin)',
                'POST /api/admin/contact-submissions/{id}/reply' => 'Reply to submission (requires admin)',
                'DELETE /api/admin/contact-submissions/{id}' => 'Delete submission (requires admin)',
                'GET /api/admin/contact-submissions/export/csv' => 'Export submissions to CSV (requires admin)',
                'GET /api/admin/chats' => 'Get all chats (requires admin)',
                'GET /api/admin/chats/analytics' => 'Get chat analytics (requires admin)',
                'GET /api/admin/chats/{id}' => 'Get chat (requires admin)',
                'DELETE /api/admin/chats/{id}' => 'Delete chat (requires admin)',
                'POST /api/admin/chats/bulk-delete' => 'Bulk delete chats (requires admin)',
                'POST /api/admin/chats/{id}/flag' => 'Flag chat (requires admin)',
                'POST /api/admin/chats/{id}/unflag' => 'Unflag chat (requires admin)',
                'POST /api/admin/chats/{id}/review' => 'Review chat (requires admin)',
                'GET /api/admin/chats/export/csv' => 'Export chats to CSV (requires admin)',
                'GET /api/admin/users/{id}/chats' => 'Get user chats (requires admin)',
                'GET /api/admin/api/analytics' => 'Get API usage analytics (requires admin)',
                'GET /api/admin/api/config' => 'Get API configuration (requires admin)',
                'PUT /api/admin/api/config' => 'Update API configuration (requires admin)',
                'DELETE /api/admin/api/config/{key}' => 'Delete API configuration (requires admin)',
                'GET /api/admin/api/usage-logs' => 'Get API usage logs (requires admin)',
                'GET /api/admin/api/usage-logs/export/csv' => 'Export API usage logs to CSV (requires admin)',
                'GET /api/admin/backups' => 'Get backup history (requires admin)',
                'POST /api/admin/backups' => 'Create database backup (requires admin)',
                'POST /api/admin/backups/{id}/restore' => 'Restore database from backup (requires admin)',
                'GET /api/admin/backups/{id}/download' => 'Download backup file (requires admin)',
                'DELETE /api/admin/backups/{id}' => 'Delete backup (requires admin)',
                'GET /api/admin/activity/login-history' => 'Get login history (requires admin)',
                'GET /api/admin/activity/active-sessions' => 'Get active sessions (requires admin)',
                'POST /api/admin/activity/sessions/{id}/force-logout' => 'Force logout session (requires admin)',
                'GET /api/admin/activity/user-activity' => 'Get user activity (requires admin)',
                'GET /api/admin/activity/failed-logins' => 'Get failed login attempts (requires admin)',
            ],
        ],
    ]);
});

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/contact', [ContactController::class, 'submitContact']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth routes
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Chat routes
    Route::post('/chat/message', [ChatController::class, 'sendMessage']);
    Route::get('/chat/history', [ChatController::class, 'getChatHistory']);
    Route::get('/chat/sessions', [ChatController::class, 'getChatSessions']);
    Route::delete('/chat/history/{id?}', [ChatController::class, 'deleteChatHistory']);

    // User profile routes
    Route::put('/user/profile', [UserController::class, 'updateProfile']);

    // Admin routes
    Route::prefix('admin')->middleware(\App\Http\Middleware\AdminMiddleware::class)->group(function () {
        Route::get('/dashboard/stats', [AdminController::class, 'dashboardStats']);
        Route::get('/users', [AdminController::class, 'getUsers']);
        Route::get('/users/{id}', [AdminController::class, 'getUser']);
        Route::post('/users', [AdminController::class, 'createUser']);
        Route::put('/users/{id}', [AdminController::class, 'updateUser']);
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
        Route::get('/audit-logs', [AdminController::class, 'getAuditLogs']);
        Route::delete('/audit-logs', [AdminController::class, 'deleteAllAuditLogs']);
        Route::get('/banned-ips', [AdminController::class, 'getBannedIps']);
        Route::post('/banned-ips', [AdminController::class, 'banIp']);
        Route::delete('/banned-ips/{id}', [AdminController::class, 'unbanIp']);
        
        // System Health routes
        Route::get('/system-health', [SystemHealthController::class, 'getSystemHealth']);
        Route::get('/error-logs', [SystemHealthController::class, 'getErrorLogsPaginated']);
        Route::delete('/error-logs', [SystemHealthController::class, 'clearErrorLogs']);
        Route::delete('/error-logs/backups', [SystemHealthController::class, 'deleteErrorLogBackups']);
        
        // Security Settings routes
        Route::get('/security-settings', [SecuritySettingsController::class, 'getSecuritySettings']);
        Route::put('/security-settings', [SecuritySettingsController::class, 'updateSecuritySettings']);
        Route::get('/security-settings/ip-whitelist', [SecuritySettingsController::class, 'getIpWhitelist']);
        Route::post('/security-settings/ip-whitelist', [SecuritySettingsController::class, 'addIpToWhitelist']);
        Route::delete('/security-settings/ip-whitelist/{id}', [SecuritySettingsController::class, 'removeIpFromWhitelist']);
        
        // Maintenance Mode routes
        Route::get('/maintenance-mode', [MaintenanceModeController::class, 'getMaintenanceMode']);
        Route::put('/maintenance-mode', [MaintenanceModeController::class, 'updateMaintenanceMode']);
        Route::post('/maintenance-mode/allowed-ips', [MaintenanceModeController::class, 'addAllowedIp']);
        Route::delete('/maintenance-mode/allowed-ips/{id}', [MaintenanceModeController::class, 'removeAllowedIp']);
        
        // Cache Management routes
        Route::get('/cache/stats', [CacheManagementController::class, 'getCacheStats']);
        Route::post('/cache/clear', [CacheManagementController::class, 'clearCache']);
        Route::post('/cache/optimize-database', [CacheManagementController::class, 'optimizeDatabase']);
        
        // Contact Submissions routes
        Route::get('/contact-submissions', [ContactSubmissionController::class, 'index']);
        Route::get('/contact-submissions/{id}', [ContactSubmissionController::class, 'show']);
        Route::put('/contact-submissions/{id}/read', [ContactSubmissionController::class, 'markRead']);
        Route::post('/contact-submissions/{id}/reply', [ContactSubmissionController::class, 'reply']);
        Route::delete('/contact-submissions/{id}', [ContactSubmissionController::class, 'destroy']);
        Route::get('/contact-submissions/export/csv', [ContactSubmissionController::class, 'export']);
        
        // Chat Management routes
        Route::get('/chats', [AdminChatController::class, 'index']);
        Route::get('/chats/analytics', [AdminChatController::class, 'analytics']);
        Route::get('/chats/export/csv', [AdminChatController::class, 'export']);
        Route::get('/chats/{id}', [AdminChatController::class, 'show']);
        Route::delete('/chats/{id}', [AdminChatController::class, 'destroy']);
        Route::post('/chats/bulk-delete', [AdminChatController::class, 'bulkDelete']);
        Route::post('/chats/{id}/flag', [AdminChatController::class, 'flagChat']);
        Route::post('/chats/{id}/unflag', [AdminChatController::class, 'unflagChat']);
        Route::post('/chats/{id}/review', [AdminChatController::class, 'reviewChat']);
        Route::get('/users/{id}/chats', [AdminChatController::class, 'getUserChats']);
        
        // API Management routes
        Route::get('/api/analytics', [ApiManagementController::class, 'analytics']);
        Route::get('/api/config', [ApiManagementController::class, 'getApiConfig']);
        Route::put('/api/config', [ApiManagementController::class, 'updateApiConfig']);
        Route::delete('/api/config/{key}', [ApiManagementController::class, 'deleteApiConfig']);
        Route::get('/api/usage-logs', [ApiManagementController::class, 'getUsageLogs']);
        Route::get('/api/usage-logs/export/csv', [ApiManagementController::class, 'exportUsageLogs']);
        
        // Backup & Restore routes
        Route::get('/backups', [BackupController::class, 'index']);
        Route::post('/backups', [BackupController::class, 'create']);
        Route::post('/backups/{id}/restore', [BackupController::class, 'restore']);
        Route::get('/backups/{id}/download', [BackupController::class, 'download']);
        Route::delete('/backups/{id}', [BackupController::class, 'destroy']);
        
        // Activity Logs & Session Management routes
        Route::get('/activity/login-history', [ActivityLogsController::class, 'getLoginHistory']);
        Route::get('/activity/active-sessions', [ActivityLogsController::class, 'getActiveSessions']);
        Route::post('/activity/sessions/{id}/force-logout', [ActivityLogsController::class, 'forceLogout']);
        Route::get('/activity/user-activity', [ActivityLogsController::class, 'getUserActivity']);
        Route::get('/activity/failed-logins', [ActivityLogsController::class, 'getFailedLoginAttempts']);
    });
});


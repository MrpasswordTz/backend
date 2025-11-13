<?php

namespace App\Http\Controllers;

use App\Models\SecuritySetting;
use App\Models\IpWhitelist;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SecuritySettingsController extends Controller
{
    /**
     * Get all security settings.
     */
    public function getSecuritySettings(Request $request)
    {
        try {
            $settings = SecuritySetting::all()->pluck('value', 'key')->toArray();
            $ipWhitelist = IpWhitelist::with('addedBy:id,username,name')
                ->orderBy('added_at', 'desc')
                ->get()
                ->map(function ($ip) {
                    return [
                        'id' => $ip->id,
                        'ip_address' => $ip->ip_address,
                        'description' => $ip->description,
                        'added_by' => $ip->addedBy ? [
                            'id' => $ip->addedBy->id,
                            'username' => $ip->addedBy->username,
                            'name' => $ip->addedBy->name,
                        ] : null,
                        'added_at' => $ip->added_at->toISOString(),
                        'created_at' => $ip->created_at->toISOString(),
                        'updated_at' => $ip->updated_at->toISOString(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'ip_whitelist' => $ipWhitelist,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting security settings', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch security settings',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update security settings.
     */
    public function updateSecuritySettings(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'password_min_length' => 'sometimes|integer|min:6|max:32',
                'password_require_uppercase' => 'sometimes|boolean',
                'password_require_lowercase' => 'sometimes|boolean',
                'password_require_numbers' => 'sometimes|boolean',
                'password_require_symbols' => 'sometimes|boolean',
                'session_timeout' => 'sometimes|integer|min:5|max:1440',
                'session_timeout_enabled' => 'sometimes|boolean',
                'ip_whitelist_enabled' => 'sometimes|boolean',
                'security_alerts_enabled' => 'sometimes|boolean',
                'security_alert_email' => 'sometimes|email|nullable',
                'failed_login_attempts_limit' => 'sometimes|integer|min:3|max:10',
                'failed_login_lockout_duration' => 'sometimes|integer|min:5|max:1440',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $updatedSettings = [];
            $allowedKeys = [
                'password_min_length',
                'password_require_uppercase',
                'password_require_lowercase',
                'password_require_numbers',
                'password_require_symbols',
                'session_timeout',
                'session_timeout_enabled',
                'ip_whitelist_enabled',
                'security_alerts_enabled',
                'security_alert_email',
                'failed_login_attempts_limit',
                'failed_login_lockout_duration',
            ];

            foreach ($allowedKeys as $key) {
                if ($request->has($key)) {
                    $value = $request->get($key);
                    // Convert boolean to string for storage
                    if (is_bool($value)) {
                        $value = $value ? '1' : '0';
                    }
                    SecuritySetting::setValue($key, (string)$value);
                    $updatedSettings[$key] = $value;
                }
            }

            // Log the action
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'admin_update_security_settings',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'details' => [
                    'updated_settings' => $updatedSettings,
                    'updated_at' => now()->toISOString(),
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Security settings updated successfully',
                'data' => [
                    'settings' => SecuritySetting::getAllSettings(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating security settings', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update security settings',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add IP to whitelist.
     */
    public function addIpToWhitelist(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'ip_address' => 'required|ip',
                'description' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $ipWhitelist = IpWhitelist::create([
                'ip_address' => $request->ip_address,
                'description' => $request->description,
                'added_by' => $request->user()->id,
                'added_at' => now(),
            ]);

            // Log the action
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'admin_add_ip_to_whitelist',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'details' => [
                    'whitelisted_ip' => $request->ip_address,
                    'description' => $request->description,
                ],
            ]);

            $ipWhitelist->load('addedBy:id,username,name');
            
            return response()->json([
                'success' => true,
                'message' => 'IP added to whitelist successfully',
                'data' => [
                    'ip_whitelist' => [
                        'id' => $ipWhitelist->id,
                        'ip_address' => $ipWhitelist->ip_address,
                        'description' => $ipWhitelist->description,
                        'added_by' => $ipWhitelist->addedBy ? [
                            'id' => $ipWhitelist->addedBy->id,
                            'username' => $ipWhitelist->addedBy->username,
                            'name' => $ipWhitelist->addedBy->name,
                        ] : null,
                        'added_at' => $ipWhitelist->added_at->toISOString(),
                        'created_at' => $ipWhitelist->created_at->toISOString(),
                        'updated_at' => $ipWhitelist->updated_at->toISOString(),
                    ],
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error adding IP to whitelist', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to add IP to whitelist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove IP from whitelist.
     */
    public function removeIpFromWhitelist(Request $request, $id)
    {
        try {
            $ipWhitelist = IpWhitelist::findOrFail($id);
            $ipAddress = $ipWhitelist->ip_address;
            $ipWhitelist->delete();

            // Log the action
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'admin_remove_ip_from_whitelist',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'details' => [
                    'removed_ip' => $ipAddress,
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'IP removed from whitelist successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing IP from whitelist', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove IP from whitelist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get IP whitelist.
     */
    public function getIpWhitelist(Request $request)
    {
        try {
            $ipWhitelist = IpWhitelist::with('addedBy:id,username,name')
                ->orderBy('added_at', 'desc')
                ->get()
                ->map(function ($ip) {
                    return [
                        'id' => $ip->id,
                        'ip_address' => $ip->ip_address,
                        'description' => $ip->description,
                        'added_by' => $ip->addedBy ? [
                            'id' => $ip->addedBy->id,
                            'username' => $ip->addedBy->username,
                            'name' => $ip->addedBy->name,
                        ] : null,
                        'added_at' => $ip->added_at->toISOString(),
                        'created_at' => $ip->created_at->toISOString(),
                        'updated_at' => $ip->updated_at->toISOString(),
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'ip_whitelist' => $ipWhitelist,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting IP whitelist', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch IP whitelist',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}


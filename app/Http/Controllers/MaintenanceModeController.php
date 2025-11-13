<?php

namespace App\Http\Controllers;

use App\Models\MaintenanceMode;
use App\Models\MaintenanceModeAllowedIp;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class MaintenanceModeController extends Controller
{
    /**
     * Get maintenance mode settings.
     */
    public function getMaintenanceMode(Request $request)
    {
        try {
            $maintenance = MaintenanceMode::getSettings();
            $allowedIps = MaintenanceModeAllowedIp::with('addedBy:id,username,name')
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
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'maintenance' => $maintenance ? [
                        'id' => $maintenance->id,
                        'enabled' => $maintenance->enabled,
                        'message' => $maintenance->message,
                        'scheduled_start' => $maintenance->scheduled_start ? $maintenance->scheduled_start->toISOString() : null,
                        'scheduled_end' => $maintenance->scheduled_end ? $maintenance->scheduled_end->toISOString() : null,
                        'is_scheduled_active' => $this->isScheduledActive($maintenance),
                    ] : null,
                    'allowed_ips' => $allowedIps,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting maintenance mode', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch maintenance mode settings',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update maintenance mode settings.
     */
    public function updateMaintenanceMode(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'enabled' => 'sometimes|boolean',
                'message' => 'sometimes|string|max:1000',
                'scheduled_start' => 'sometimes|nullable|date',
                'scheduled_end' => 'sometimes|nullable|date|after:scheduled_start',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $maintenance = MaintenanceMode::firstOrCreate([], [
                'enabled' => false,
                'message' => 'We are currently performing scheduled maintenance. Please check back shortly.',
            ]);

            if ($request->has('enabled')) {
                $maintenance->enabled = $request->enabled;
                
                // Log the action
                AuditLog::create([
                    'user_id' => $request->user()->id,
                    'action' => $request->enabled ? 'maintenance_mode_enabled' : 'maintenance_mode_disabled',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'details' => [
                        'enabled' => $request->enabled,
                        'message' => $request->get('message', $maintenance->message),
                    ],
                ]);
            }

            if ($request->has('message')) {
                $maintenance->message = $request->message;
            }

            if ($request->has('scheduled_start')) {
                $maintenance->scheduled_start = $request->scheduled_start ? now()->parse($request->scheduled_start) : null;
            }

            if ($request->has('scheduled_end')) {
                $maintenance->scheduled_end = $request->scheduled_end ? now()->parse($request->scheduled_end) : null;
            }

            $maintenance->save();

            return response()->json([
                'success' => true,
                'message' => 'Maintenance mode settings updated successfully',
                'data' => [
                    'maintenance' => [
                        'id' => $maintenance->id,
                        'enabled' => $maintenance->enabled,
                        'message' => $maintenance->message,
                        'scheduled_start' => $maintenance->scheduled_start ? $maintenance->scheduled_start->toISOString() : null,
                        'scheduled_end' => $maintenance->scheduled_end ? $maintenance->scheduled_end->toISOString() : null,
                        'is_scheduled_active' => $this->isScheduledActive($maintenance),
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating maintenance mode', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to update maintenance mode settings',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add IP to maintenance mode allowed list.
     */
    public function addAllowedIp(Request $request)
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

            $allowedIp = MaintenanceModeAllowedIp::create([
                'ip_address' => $request->ip_address,
                'description' => $request->description,
                'added_by' => $request->user()->id,
                'added_at' => now(),
            ]);

            // Log the action
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'maintenance_mode_add_allowed_ip',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'details' => [
                    'allowed_ip' => $request->ip_address,
                    'description' => $request->description,
                ],
            ]);

            $allowedIp->load('addedBy:id,username,name');

            return response()->json([
                'success' => true,
                'message' => 'IP added to allowed list successfully',
                'data' => [
                    'allowed_ip' => [
                        'id' => $allowedIp->id,
                        'ip_address' => $allowedIp->ip_address,
                        'description' => $allowedIp->description,
                        'added_by' => $allowedIp->addedBy ? [
                            'id' => $allowedIp->addedBy->id,
                            'username' => $allowedIp->addedBy->username,
                            'name' => $allowedIp->addedBy->name,
                        ] : null,
                        'added_at' => $allowedIp->added_at->toISOString(),
                    ],
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error adding allowed IP', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to add IP to allowed list',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove IP from maintenance mode allowed list.
     */
    public function removeAllowedIp(Request $request, $id)
    {
        try {
            $allowedIp = MaintenanceModeAllowedIp::findOrFail($id);
            $ipAddress = $allowedIp->ip_address;
            $allowedIp->delete();

            // Log the action
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'maintenance_mode_remove_allowed_ip',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'details' => [
                    'removed_ip' => $ipAddress,
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'IP removed from allowed list successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error removing allowed IP', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove IP from allowed list',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check if scheduled maintenance is active.
     */
    private function isScheduledActive(MaintenanceMode $maintenance): bool
    {
        if (!$maintenance->scheduled_start || !$maintenance->scheduled_end) {
            return false;
        }

        $now = now();
        return $now->between($maintenance->scheduled_start, $maintenance->scheduled_end);
    }
}

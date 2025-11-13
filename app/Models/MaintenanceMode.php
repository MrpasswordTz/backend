<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaintenanceMode extends Model
{
    use HasFactory;

    protected $table = 'maintenance_mode';

    protected $fillable = [
        'enabled',
        'message',
        'scheduled_start',
        'scheduled_end',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'scheduled_start' => 'datetime',
        'scheduled_end' => 'datetime',
    ];

    /**
     * Get the current maintenance mode status.
     */
    public static function isEnabled(): bool
    {
        $maintenance = self::first();
        if (!$maintenance) {
            return false;
        }

        // Check if scheduled maintenance is active
        if ($maintenance->scheduled_start && $maintenance->scheduled_end) {
            $now = now();
            if ($now->between($maintenance->scheduled_start, $maintenance->scheduled_end)) {
                return true;
            }
        }

        return $maintenance->enabled;
    }

    /**
     * Get maintenance mode settings.
     */
    public static function getSettings(): ?self
    {
        return self::first();
    }

    /**
     * Enable maintenance mode.
     */
    public static function enable(?string $message = null): self
    {
        $maintenance = self::firstOrCreate([], [
            'enabled' => false,
            'message' => 'We are currently performing scheduled maintenance. Please check back shortly.',
        ]);

        $maintenance->enabled = true;
        if ($message) {
            $maintenance->message = $message;
        }
        $maintenance->save();

        return $maintenance;
    }

    /**
     * Disable maintenance mode.
     */
    public static function disable(): self
    {
        $maintenance = self::firstOrCreate([], [
            'enabled' => false,
            'message' => 'We are currently performing scheduled maintenance. Please check back shortly.',
        ]);

        $maintenance->enabled = false;
        $maintenance->scheduled_start = null;
        $maintenance->scheduled_end = null;
        $maintenance->save();

        return $maintenance;
    }

    /**
     * Check if IP is allowed during maintenance.
     */
    public static function isIpAllowed(string $ipAddress): bool
    {
        return \App\Models\MaintenanceModeAllowedIp::where('ip_address', $ipAddress)->exists();
    }
}

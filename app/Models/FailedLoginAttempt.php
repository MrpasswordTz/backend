<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class FailedLoginAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'ip_address',
        'attempted_at',
        'locked',
        'locked_until',
        'attempt_count',
    ];

    protected $casts = [
        'attempted_at' => 'datetime',
        'locked_until' => 'datetime',
        'locked' => 'boolean',
        'attempt_count' => 'integer',
    ];

    /**
     * Record a failed login attempt.
     */
    public static function recordFailedAttempt(string $email, string $ipAddress): self
    {
        // Get or create failed login attempt record
        $attempt = self::where('email', $email)
            ->where('ip_address', $ipAddress)
            ->first();

        if ($attempt) {
            $attempt->attempt_count += 1;
            $attempt->attempted_at = now();
            $attempt->save();
        } else {
            $attempt = self::create([
                'email' => $email,
                'ip_address' => $ipAddress,
                'attempted_at' => now(),
                'attempt_count' => 1,
                'locked' => false,
            ]);
        }

        return $attempt;
    }

    /**
     * Check if account is locked.
     */
    public function isLocked(): bool
    {
        if (!$this->locked) {
            return false;
        }

        // Check if lockout period has expired
        if ($this->locked_until && $this->locked_until->isPast()) {
            $this->resetAttempts();
            return false;
        }

        return true;
    }

    /**
     * Lock the account.
     */
    public function lock(int $durationMinutes): void
    {
        $this->locked = true;
        $this->locked_until = now()->addMinutes($durationMinutes);
        $this->save();
    }

    /**
     * Reset failed login attempts.
     */
    public function resetAttempts(): void
    {
        $this->attempt_count = 0;
        $this->locked = false;
        $this->locked_until = null;
        $this->save();
    }

    /**
     * Get remaining lockout time in minutes.
     */
    public function getRemainingLockoutTime(): ?int
    {
        if (!$this->locked || !$this->locked_until) {
            return null;
        }

        // Refresh the model to get the latest state
        $this->refresh();
        
        // Check if lockout period has expired
        if ($this->locked_until->isPast()) {
            $this->resetAttempts();
            return null;
        }

        // Calculate remaining minutes (now to locked_until)
        $remaining = (int)ceil(now()->diffInMinutes($this->locked_until, false));
        return $remaining > 0 ? $remaining : 1; // Return at least 1 minute if still locked
    }

    /**
     * Clean up old failed login attempts (older than 24 hours).
     */
    public static function cleanup(): void
    {
        self::where('attempted_at', '<', now()->subDay())
            ->where('locked', false)
            ->delete();
    }
}

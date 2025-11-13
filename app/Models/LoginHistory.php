<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginHistory extends Model
{
    use HasFactory;

    protected $table = 'login_history';

    protected $fillable = [
        'user_id',
        'email',
        'ip_address',
        'user_agent',
        'status',
        'failure_reason',
        'session_id',
        'logged_out_at',
    ];

    protected $casts = [
        'logged_out_at' => 'datetime',
    ];

    /**
     * Get the user associated with this login history.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

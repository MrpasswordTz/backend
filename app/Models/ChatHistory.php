<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatHistory extends Model
{
    use HasFactory;

    protected $table = 'chat_history';

    protected $fillable = [
        'user_id',
        'message',
        'response',
        'session_id',
        'flagged',
        'reviewed',
        'flagged_by',
        'reviewed_by',
        'flagged_at',
        'reviewed_at',
        'flag_reason',
    ];

    protected $casts = [
        'flagged' => 'boolean',
        'reviewed' => 'boolean',
        'flagged_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Get the user that owns the chat history.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who flagged this chat.
     */
    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }

    /**
     * Get the user who reviewed this chat.
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}


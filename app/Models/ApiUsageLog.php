<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiUsageLog extends Model
{
    use HasFactory;

    // Disable updated_at since we only use created_at
    const UPDATED_AT = null;

    protected $fillable = [
        'api_provider',
        'endpoint',
        'user_id',
        'model',
        'input_tokens',
        'output_tokens',
        'total_tokens',
        'response_time_ms',
        'status_code',
        'success',
        'error_message',
        'request_data',
        'response_data',
        'ip_address',
        'user_agent',
        'cost',
    ];

    protected $casts = [
        'success' => 'boolean',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'total_tokens' => 'integer',
        'response_time_ms' => 'integer',
        'status_code' => 'integer',
        'cost' => 'decimal:6',
        'created_at' => 'datetime',
    ];

    /**
     * Get the user that made the API call.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

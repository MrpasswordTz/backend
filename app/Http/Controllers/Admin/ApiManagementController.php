<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiUsageLog;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ApiManagementController extends Controller
{
    /**
     * Get API usage analytics.
     */
    public function analytics(Request $request)
    {
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');
        
        $query = ApiUsageLog::query();
        
        // Apply date range filter
        if ($dateFrom && $dateTo) {
            $startDate = \Carbon\Carbon::parse($dateFrom)->startOfDay();
            $endDate = \Carbon\Carbon::parse($dateTo)->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
        } else {
            // Default to last 30 days
            $startDate = now()->subDays(30)->startOfDay();
            $endDate = now()->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        // Total API calls
        $totalCalls = (clone $query)->count();
        
        // Successful calls
        $successfulCalls = (clone $query)->where('success', true)->count();
        
        // Failed calls
        $failedCalls = (clone $query)->where('success', false)->count();
        
        // Total tokens used
        $totalTokens = (clone $query)->sum('total_tokens');
        $inputTokens = (clone $query)->sum('input_tokens');
        $outputTokens = (clone $query)->sum('output_tokens');
        
        // Total cost
        $totalCost = (clone $query)->sum('cost');
        
        // Average response time
        $avgResponseTime = (clone $query)->whereNotNull('response_time_ms')->avg('response_time_ms');
        
        // Calls by provider
        $callsByProvider = (clone $query)
            ->selectRaw('api_provider, COUNT(*) as count, SUM(total_tokens) as tokens, SUM(cost) as cost')
            ->groupBy('api_provider')
            ->get()
            ->map(function ($item) {
                return [
                    'provider' => $item->api_provider,
                    'count' => (int)$item->count,
                    'tokens' => (int)$item->tokens,
                    'cost' => (float)$item->cost,
                ];
            })
            ->values()
            ->toArray();
        
        // Calls per day
        $callsPerDay = (clone $query)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count, SUM(total_tokens) as tokens')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'count' => (int)$item->count,
                    'tokens' => (int)$item->tokens,
                ];
            })
            ->values()
            ->toArray();
        
        // Error rate by provider
        $errorRateByProvider = (clone $query)
            ->selectRaw('api_provider, COUNT(*) as total, SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed')
            ->groupBy('api_provider')
            ->get()
            ->map(function ($item) {
                $errorRate = $item->total > 0 ? ($item->failed / $item->total) * 100 : 0;
                return [
                    'provider' => $item->api_provider,
                    'total' => (int)$item->total,
                    'failed' => (int)$item->failed,
                    'error_rate' => round($errorRate, 2),
                ];
            })
            ->values()
            ->toArray();
        
        // Top users by API usage
        $topUsers = (clone $query)
            ->selectRaw('user_id, COUNT(*) as count, SUM(total_tokens) as tokens')
            ->groupBy('user_id')
            ->with('user:id,username,name')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'user_id' => $item->user_id,
                    'username' => $item->user->username ?? 'Unknown',
                    'name' => $item->user->name ?? 'Unknown',
                    'count' => (int)$item->count,
                    'tokens' => (int)$item->tokens,
                ];
            })
            ->values()
            ->toArray();
        
        // Recent errors
        $recentErrors = (clone $query)
            ->where('success', false)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'api_provider' => $item->api_provider,
                    'endpoint' => $item->endpoint,
                    'status_code' => $item->status_code,
                    'error_message' => $item->error_message,
                    'created_at' => $item->created_at->toDateTimeString(),
                ];
            })
            ->values()
            ->toArray();

        return response()->json([
            'success' => true,
            'analytics' => [
                'total_calls' => $totalCalls,
                'successful_calls' => $successfulCalls,
                'failed_calls' => $failedCalls,
                'total_tokens' => (int)$totalTokens,
                'input_tokens' => (int)$inputTokens,
                'output_tokens' => (int)$outputTokens,
                'total_cost' => (float)$totalCost,
                'avg_response_time_ms' => round($avgResponseTime ?? 0, 2),
                'calls_by_provider' => $callsByProvider,
                'calls_per_day' => $callsPerDay,
                'error_rate_by_provider' => $errorRateByProvider,
                'top_users' => $topUsers,
                'recent_errors' => $recentErrors,
                'date_from' => isset($startDate) ? $startDate->toDateString() : ($dateFrom ?? now()->subDays(30)->toDateString()),
                'date_to' => isset($endDate) ? $endDate->toDateString() : ($dateTo ?? now()->toDateString()),
            ],
        ]);
    }

    /**
     * Get API configuration (available API keys from .env).
     */
    public function getApiConfig(Request $request)
    {
        $envPath = base_path('.env');
        $apiKeys = [];
        
        // Define API keys to look for
        $keysToCheck = [
            'HF_TOKEN' => 'Hugging Face API Token',
            'OPENAI_API_KEY' => 'OpenAI API Key',
            'ANTHROPIC_API_KEY' => 'Anthropic API Key',
            'GOOGLE_AI_API_KEY' => 'Google AI API Key',
            'COHERE_API_KEY' => 'Cohere API Key',
        ];
        
        if (File::exists($envPath)) {
            $envContent = File::get($envPath);
            
            foreach ($keysToCheck as $key => $name) {
                // Extract value from .env file
                if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $envContent, $matches)) {
                    $value = trim($matches[1]);
                    // Remove quotes if present
                    $value = trim($value, '"\'');
                    
                    // Show masked version (first 4 and last 4 characters)
                    $maskedValue = '';
                    if (strlen($value) > 8) {
                        $maskedValue = substr($value, 0, 4) . str_repeat('*', strlen($value) - 8) . substr($value, -4);
                    } else {
                        $maskedValue = str_repeat('*', strlen($value));
                    }
                    
                    $apiKeys[] = [
                        'key' => $key,
                        'name' => $name,
                        'value' => $value, // Full value for editing
                        'masked_value' => $maskedValue, // Masked value for display
                        'is_set' => !empty($value),
                    ];
                } else {
                    // Key not found in .env
                    $apiKeys[] = [
                        'key' => $key,
                        'name' => $name,
                        'value' => '',
                        'masked_value' => '',
                        'is_set' => false,
                    ];
                }
            }
        }
        
        return response()->json([
            'success' => true,
            'api_keys' => $apiKeys,
        ]);
    }

    /**
     * Update API configuration.
     */
    public function updateApiConfig(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'key' => 'required|string|in:HF_TOKEN,OPENAI_API_KEY,ANTHROPIC_API_KEY,GOOGLE_AI_API_KEY,COHERE_API_KEY',
            'value' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $envPath = base_path('.env');
        
        if (!File::exists($envPath)) {
            return response()->json([
                'success' => false,
                'message' => '.env file not found'
            ], 404);
        }

        $key = $request->key;
        $value = $request->value ?? '';
        
        // Read .env file
        $envContent = File::get($envPath);
        
        // Check if key exists
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        
        if (preg_match($pattern, $envContent)) {
            // Update existing key
            $envContent = preg_replace(
                $pattern,
                $key . '=' . ($value ? '"' . $value . '"' : ''),
                $envContent
            );
        } else {
            // Add new key
            $envContent .= "\n" . $key . '=' . ($value ? '"' . $value . '"' : '');
        }
        
        // Write back to .env file
        File::put($envPath, $envContent);
        
        // Clear config cache
        Artisan::call('config:clear');
        
        // Log the action
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'admin_update_api_config',
            'description' => 'Updated API key: ' . $key,
            'ip_address' => $request->ip(),
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'API key updated successfully',
        ]);
    }

    /**
     * Delete API configuration (remove from .env).
     */
    public function deleteApiConfig(Request $request, $key)
    {
        // Validate key
        $allowedKeys = ['HF_TOKEN', 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GOOGLE_AI_API_KEY', 'COHERE_API_KEY'];
        if (!in_array($key, $allowedKeys)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid API key',
                'errors' => ['key' => 'The key must be one of: ' . implode(', ', $allowedKeys)]
            ], 422);
        }

        $envPath = base_path('.env');
        
        if (!File::exists($envPath)) {
            return response()->json([
                'success' => false,
                'message' => '.env file not found'
            ], 404);
        }
        
        // Read .env file
        $envContent = File::get($envPath);
        
        // Remove key from .env (handle both with and without quotes, and handle newlines)
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        $envContent = preg_replace($pattern, '', $envContent);
        
        // Remove any empty lines that might be left
        $envContent = preg_replace('/\n\n+/', "\n\n", $envContent);
        $envContent = trim($envContent) . "\n";
        
        // Write back to .env file
        File::put($envPath, $envContent);
        
        // Clear config cache
        Artisan::call('config:clear');
        
        // Log the action
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'admin_delete_api_config',
            'description' => 'Deleted API key: ' . $key,
            'ip_address' => $request->ip(),
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'API key deleted successfully',
        ]);
    }

    /**
     * Get API usage logs with pagination.
     */
    public function getUsageLogs(Request $request)
    {
        $query = ApiUsageLog::with('user')->orderBy('created_at', 'desc');
        
        // Filter by provider
        if ($request->has('provider') && $request->provider !== 'all') {
            $query->where('api_provider', $request->provider);
        }
        
        // Filter by success status
        if ($request->has('success') && $request->success !== 'all') {
            $query->where('success', $request->success === 'true');
        }
        
        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('endpoint', 'like', '%' . $search . '%')
                  ->orWhere('model', 'like', '%' . $search . '%')
                  ->orWhere('error_message', 'like', '%' . $search . '%');
            });
        }
        
        // Date range filter
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        
        $perPage = $request->get('per_page', 20);
        $perPage = min(max((int)$perPage, 1), 100);
        
        $logs = $query->paginate($perPage);
        
        return response()->json([
            'success' => true,
            'logs' => $logs,
        ]);
    }

    /**
     * Export API usage logs to CSV.
     */
    public function exportUsageLogs(Request $request)
    {
        $query = ApiUsageLog::with('user');
        
        // Apply filters (same as getUsageLogs)
        if ($request->has('provider') && $request->provider !== 'all') {
            $query->where('api_provider', $request->provider);
        }
        if ($request->has('success') && $request->success !== 'all') {
            $query->where('success', $request->success === 'true');
        }
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('endpoint', 'like', '%' . $search . '%')
                  ->orWhere('model', 'like', '%' . $search . '%')
                  ->orWhere('error_message', 'like', '%' . $search . '%');
            });
        }
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        
        $logs = $query->orderBy('created_at', 'desc')->get();
        
        $filename = 'api_usage_logs_' . now()->format('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];
        
        $callback = function () use ($logs) {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'ID', 'API Provider', 'Endpoint', 'User', 'Model', 'Input Tokens',
                'Output Tokens', 'Total Tokens', 'Response Time (ms)', 'Status Code',
                'Success', 'Error Message', 'Cost', 'IP Address', 'Created At'
            ]);
            
            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->id,
                    $log->api_provider,
                    $log->endpoint,
                    $log->user->username ?? 'N/A',
                    $log->model,
                    $log->input_tokens,
                    $log->output_tokens,
                    $log->total_tokens,
                    $log->response_time_ms,
                    $log->status_code,
                    $log->success ? 'Yes' : 'No',
                    $log->error_message,
                    $log->cost,
                    $log->ip_address,
                    $log->created_at,
                ]);
            }
            fclose($file);
        };
        
        // Log the action
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'admin_export_api_usage_logs',
            'description' => 'Exported API usage logs',
            'ip_address' => $request->ip(),
        ]);
        
        return new StreamedResponse($callback, 200, $headers);
    }
}

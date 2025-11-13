<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SystemHealthController extends Controller
{
    /**
     * Get system health statistics.
     */
    public function getSystemHealth(Request $request)
    {
        try {
            $serverStats = $this->getServerStats();
            $databaseStats = $this->getDatabaseStats();
            $apiHealth = $this->getApiHealth();
            $errorLogs = $this->getErrorLogs();
            $performanceMetrics = $this->getPerformanceMetrics();

            return response()->json([
                'success' => true,
                'data' => [
                    'server' => $serverStats,
                    'database' => $databaseStats,
                    'api' => $apiHealth,
                    'error_logs' => $errorLogs,
                    'performance' => $performanceMetrics,
                    'timestamp' => now()->toISOString(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching system health', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch system health data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get server statistics (CPU, memory, disk).
     */
    private function getServerStats()
    {
        $stats = [
            'cpu' => null,
            'memory' => null,
            'disk' => null,
            'uptime' => null,
            'load_average' => null,
        ];

        try {
            // Memory stats
            if (function_exists('exec')) {
                // Memory usage
                $memoryInfo = [];
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    // Windows
                    exec('wmic OS get TotalVisibleMemorySize,FreePhysicalMemory /value', $memoryInfo);
                } else {
                    // Linux/Unix
                    exec('free -m', $memoryInfo);
                    if (!empty($memoryInfo)) {
                        $memoryLine = explode(' ', preg_replace('/\s+/', ' ', $memoryInfo[1]));
                        $totalMemory = isset($memoryLine[1]) ? (int)$memoryLine[1] : 0;
                        $usedMemory = isset($memoryLine[2]) ? (int)$memoryLine[2] : 0;
                        $freeMemory = isset($memoryLine[3]) ? (int)$memoryLine[3] : 0;
                        
                        $stats['memory'] = [
                            'total' => $totalMemory,
                            'used' => $usedMemory,
                            'free' => $freeMemory,
                            'used_percent' => $totalMemory > 0 ? round(($usedMemory / $totalMemory) * 100, 2) : 0,
                            'unit' => 'MB',
                        ];
                    }
                }

                // Disk usage
                $diskInfo = [];
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    exec('wmic logicaldisk get size,freespace,caption', $diskInfo);
                } else {
                    $diskTotal = disk_total_space('/');
                    $diskFree = disk_free_space('/');
                    $diskUsed = $diskTotal - $diskFree;
                    
                    $stats['disk'] = [
                        'total' => round($diskTotal / (1024 * 1024 * 1024), 2),
                        'used' => round($diskUsed / (1024 * 1024 * 1024), 2),
                        'free' => round($diskFree / (1024 * 1024 * 1024), 2),
                        'used_percent' => $diskTotal > 0 ? round(($diskUsed / $diskTotal) * 100, 2) : 0,
                        'unit' => 'GB',
                    ];
                }

                // Load average (Linux/Unix only)
                if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
                    $loadAvg = sys_getloadavg();
                    if ($loadAvg) {
                        $stats['load_average'] = [
                            '1min' => round($loadAvg[0], 2),
                            '5min' => round($loadAvg[1], 2),
                            '15min' => round($loadAvg[2], 2),
                        ];
                    }

                    // Uptime
                    $uptime = shell_exec('uptime -p');
                    if ($uptime) {
                        $stats['uptime'] = trim($uptime);
                    }
                }

                // CPU usage (simplified - uses system load)
                if (isset($stats['load_average'])) {
                    $cpuCores = (int)shell_exec('nproc') ?: 1;
                    $cpuUsage = min(100, ($stats['load_average']['1min'] / $cpuCores) * 100);
                    $stats['cpu'] = [
                        'usage_percent' => round($cpuUsage, 2),
                        'cores' => $cpuCores,
                        'load_average' => $stats['load_average']['1min'],
                    ];
                }
            }

            // Fallback: PHP memory info if system commands fail
            if (!$stats['memory']) {
                $memoryLimit = ini_get('memory_limit');
                $memoryUsed = memory_get_usage(true);
                $memoryPeak = memory_get_peak_usage(true);
                
                // Convert memory limit to bytes
                $memoryLimitBytes = $this->convertToBytes($memoryLimit);
                
                $stats['memory'] = [
                    'total' => round($memoryLimitBytes / (1024 * 1024), 2),
                    'used' => round($memoryUsed / (1024 * 1024), 2),
                    'peak' => round($memoryPeak / (1024 * 1024), 2),
                    'used_percent' => $memoryLimitBytes > 0 ? round(($memoryUsed / $memoryLimitBytes) * 100, 2) : 0,
                    'unit' => 'MB',
                    'type' => 'php',
                ];
            }

            // Fallback: Disk info using PHP functions
            if (!$stats['disk']) {
                $diskTotal = disk_total_space(base_path());
                $diskFree = disk_free_space(base_path());
                if ($diskTotal && $diskFree) {
                    $diskUsed = $diskTotal - $diskFree;
                    $stats['disk'] = [
                        'total' => round($diskTotal / (1024 * 1024 * 1024), 2),
                        'used' => round($diskUsed / (1024 * 1024 * 1024), 2),
                        'free' => round($diskFree / (1024 * 1024 * 1024), 2),
                        'used_percent' => round(($diskUsed / $diskTotal) * 100, 2),
                        'unit' => 'GB',
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::error('Error getting server stats', ['error' => $e->getMessage()]);
        }

        return $stats;
    }

    /**
     * Get database statistics.
     */
    private function getDatabaseStats()
    {
        $stats = [
            'size' => null,
            'tables' => [],
            'connections' => null,
            'query_performance' => null,
        ];

        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            // Database size
            if ($driver === 'mysql') {
                $databaseName = config('database.connections.mysql.database');
                $sizeQuery = DB::selectOne("
                    SELECT 
                        ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                    FROM information_schema.tables 
                    WHERE table_schema = ?
                ", [$databaseName]);
                
                if ($sizeQuery) {
                    $stats['size'] = [
                        'value' => (float)$sizeQuery->size_mb,
                        'unit' => 'MB',
                    ];
                }

                // Table sizes (using row_count instead of rows to avoid reserved keyword)
                $tableSizes = DB::select("
                    SELECT 
                        table_name AS name,
                        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb,
                        table_rows AS row_count
                    FROM information_schema.tables 
                    WHERE table_schema = ?
                    ORDER BY (data_length + index_length) DESC
                    LIMIT 10
                ", [$databaseName]);
                
                $stats['tables'] = array_map(function ($table) {
                    return [
                        'name' => $table->name,
                        'size_mb' => (float)$table->size_mb,
                        'rows' => (int)($table->row_count ?? 0),
                    ];
                }, $tableSizes);

                // Connection status
                $connections = DB::selectOne("SHOW STATUS WHERE Variable_name = 'Threads_connected'");
                if ($connections) {
                    $stats['connections'] = (int)$connections->Value;
                }

                // Query performance (simplified)
                $slowQueries = DB::selectOne("SHOW STATUS WHERE Variable_name = 'Slow_queries'");
                $totalQueries = DB::selectOne("SHOW STATUS WHERE Variable_name = 'Queries'");
                
                if ($slowQueries && $totalQueries) {
                    $stats['query_performance'] = [
                        'total_queries' => (int)$totalQueries->Value,
                        'slow_queries' => (int)$slowQueries->Value,
                        'slow_query_percent' => (int)$totalQueries->Value > 0 
                            ? round(((int)$slowQueries->Value / (int)$totalQueries->Value) * 100, 2) 
                            : 0,
                    ];
                }
            } elseif ($driver === 'sqlite') {
                $dbPath = database_path('database.sqlite');
                if (File::exists($dbPath)) {
                    $size = File::size($dbPath);
                    $stats['size'] = [
                        'value' => round($size / (1024 * 1024), 2),
                        'unit' => 'MB',
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::error('Error getting database stats', ['error' => $e->getMessage()]);
        }

        return $stats;
    }

    /**
     * Get API health information.
     */
    private function getApiHealth()
    {
        $health = [
            'status' => 'healthy',
            'endpoints' => [],
            'response_times' => [],
            'error_rate' => 0,
        ];

        try {
            // Check database connection
            try {
                DB::connection()->getPdo();
                $health['endpoints'][] = [
                    'name' => 'Database',
                    'status' => 'healthy',
                    'response_time' => null,
                ];
            } catch (\Exception $e) {
                $health['status'] = 'degraded';
                $health['endpoints'][] = [
                    'name' => 'Database',
                    'status' => 'unhealthy',
                    'error' => $e->getMessage(),
                ];
            }

            // Check storage
            try {
                Storage::disk('local')->exists('test');
                $health['endpoints'][] = [
                    'name' => 'Storage',
                    'status' => 'healthy',
                    'response_time' => null,
                ];
            } catch (\Exception $e) {
                $health['status'] = 'degraded';
                $health['endpoints'][] = [
                    'name' => 'Storage',
                    'status' => 'unhealthy',
                    'error' => $e->getMessage(),
                ];
            }

            // Check cache (if using Redis/Memcached)
            try {
                cache()->put('health_check', 'ok', 1);
                $cacheStatus = cache()->get('health_check') === 'ok';
                $health['endpoints'][] = [
                    'name' => 'Cache',
                    'status' => $cacheStatus ? 'healthy' : 'unhealthy',
                    'response_time' => null,
                ];
            } catch (\Exception $e) {
                $health['endpoints'][] = [
                    'name' => 'Cache',
                    'status' => 'unhealthy',
                    'error' => $e->getMessage(),
                ];
            }

        } catch (\Exception $e) {
            Log::error('Error checking API health', ['error' => $e->getMessage()]);
            $health['status'] = 'unhealthy';
        }

        return $health;
    }

    /**
     * Get error logs.
     */
    private function getErrorLogs($limit = 50)
    {
        $logs = [];

        try {
            $logPath = storage_path('logs/laravel.log');
            
            if (File::exists($logPath)) {
                // Read file in chunks to handle large log files
                $file = fopen($logPath, 'r');
                if ($file) {
                    // Get file size and read from end if file is large
                    $fileSize = filesize($logPath);
                    $chunkSize = min(100000, $fileSize); // Read last 100KB
                    $start = max(0, $fileSize - $chunkSize);
                    
                    fseek($file, $start);
                    if ($start > 0) {
                        // Skip partial line
                        fgets($file);
                    }
                    
                    $lines = [];
                    while (($line = fgets($file)) !== false) {
                        $lines[] = trim($line);
                    }
                    fclose($file);
                    
                    // Parse log entries (Laravel log format: [YYYY-MM-DD HH:MM:SS] local.LEVEL: message)
                    $currentLog = null;
                    foreach ($lines as $line) {
                        // Match Laravel log format: [2024-01-01 12:00:00] local.ERROR: message
                        if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+):\s*(.+)$/', $line, $matches)) {
                            if ($currentLog) {
                                $logs[] = $currentLog;
                            }
                            $currentLog = [
                                'timestamp' => $matches[1],
                                'environment' => $matches[2],
                                'level' => strtoupper($matches[3]),
                                'message' => $matches[4],
                                'stack' => '',
                            ];
                        } elseif ($currentLog && !empty(trim($line))) {
                            // Continuation of log entry (stack trace, etc.)
                            if (strpos($line, 'Stack trace:') !== false || strpos($line, '#') === 0 || strpos($line, 'at ') === 0) {
                                $currentLog['stack'] .= $line . "\n";
                            } else {
                                // Might be continuation of message
                                $currentLog['message'] .= ' ' . $line;
                            }
                        }
                    }
                    
                    if ($currentLog) {
                        $logs[] = $currentLog;
                    }
                    
                    // Reverse to show newest first
                    $logs = array_reverse($logs);
                    $logs = array_slice($logs, 0, $limit);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error reading error logs', ['error' => $e->getMessage()]);
        }

        return [
            'logs' => $logs,
            'total' => count($logs),
        ];
    }

    /**
     * Get performance metrics.
     */
    private function getPerformanceMetrics()
    {
        $metrics = [
            'response_time' => null,
            'memory_usage' => null,
            'database_queries' => null,
            'cache_hits' => null,
        ];

        try {
            // Memory usage
            $metrics['memory_usage'] = [
                'current' => round(memory_get_usage(true) / (1024 * 1024), 2),
                'peak' => round(memory_get_peak_usage(true) / (1024 * 1024), 2),
                'unit' => 'MB',
            ];

            // PHP version and configuration
            $metrics['php'] = [
                'version' => PHP_VERSION,
                'max_execution_time' => ini_get('max_execution_time'),
                'memory_limit' => ini_get('memory_limit'),
                'post_max_size' => ini_get('post_max_size'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
            ];

            // Laravel version
            $metrics['laravel'] = [
                'version' => app()->version(),
                'environment' => app()->environment(),
            ];

        } catch (\Exception $e) {
            Log::error('Error getting performance metrics', ['error' => $e->getMessage()]);
        }

        return $metrics;
    }

    /**
     * Convert memory limit string to bytes.
     */
    private function convertToBytes($memoryLimit)
    {
        $memoryLimit = trim($memoryLimit);
        $last = strtolower($memoryLimit[strlen($memoryLimit) - 1]);
        $value = (int)$memoryLimit;

        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }

        return $value;
    }

    /**
     * Get error logs with pagination.
     */
    public function getErrorLogsPaginated(Request $request)
    {
        try {
            $page = (int)$request->get('page', 1);
            $perPage = min(max((int)$request->get('per_page', 50), 1), 100);
            $level = $request->get('level', 'all');
            
            $allLogs = $this->getErrorLogs(1000); // Get more logs for filtering
            $logs = $allLogs['logs'];

            // Filter by level
            if ($level !== 'all') {
                $logs = array_filter($logs, function ($log) use ($level) {
                    return strtolower($log['level']) === strtolower($level);
                });
                $logs = array_values($logs);
            }

            // Paginate
            $total = count($logs);
            $offset = ($page - 1) * $perPage;
            $paginatedLogs = array_slice($logs, $offset, $perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'logs' => $paginatedLogs,
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                    'last_page' => ceil($total / $perPage),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting error logs', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch error logs',
            ], 500);
        }
    }

    /**
     * Clear error logs.
     */
    public function clearErrorLogs(Request $request)
    {
        try {
            $logPath = storage_path('logs/laravel.log');
            
            if (File::exists($logPath)) {
                // Create a backup before clearing (optional but recommended)
                $backupPath = storage_path('logs/laravel-' . date('Y-m-d-His') . '.log');
                File::copy($logPath, $backupPath);
                
                // Clear the log file
                File::put($logPath, '');
                
                // Log the action
                AuditLog::create([
                    'user_id' => $request->user()->id,
                    'action' => 'admin_clear_error_logs',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'details' => [
                        'backup_path' => $backupPath,
                        'cleared_at' => now()->toISOString(),
                    ],
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Error logs cleared successfully',
                    'backup_path' => $backupPath,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Log file does not exist',
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error clearing error logs', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear error logs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete error log backup files.
     */
    public function deleteErrorLogBackups(Request $request)
    {
        try {
            $logsPath = storage_path('logs');
            $backupFiles = glob($logsPath . '/laravel-*.log');
            
            $deletedCount = 0;
            foreach ($backupFiles as $file) {
                if (File::exists($file)) {
                    File::delete($file);
                    $deletedCount++;
                }
            }

            // Log the action
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'admin_delete_error_log_backups',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'details' => [
                    'deleted_count' => $deletedCount,
                    'deleted_at' => now()->toISOString(),
                ],
            ]);

            return response()->json([
                'success' => true,
                'message' => "Deleted {$deletedCount} backup file(s)",
                'deleted_count' => $deletedCount,
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting error log backups', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete error log backups',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}


<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CacheManagementController extends Controller
{
    /**
     * Get cache statistics.
     */
    public function getCacheStats(Request $request)
    {
        try {
            $stats = [
                'cache_driver' => config('cache.default'),
                'cache_prefix' => config('cache.prefix'),
                'cache_connection' => config('cache.stores.' . config('cache.default') . '.connection'),
            ];

            // Try to get cache stats (if supported by driver)
            try {
                $stats['cache_size'] = $this->getCacheSize();
            } catch (\Exception $e) {
                $stats['cache_size'] = 'N/A';
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => $stats,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting cache stats', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch cache statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear application cache.
     */
    public function clearCache(Request $request)
    {
        try {
            $type = $request->get('type', 'all');

            // Log the action
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'cache_cleared',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'details' => [
                    'cache_type' => $type,
                    'cleared_at' => now()->toISOString(),
                ],
            ]);

            switch ($type) {
                case 'application':
                    Artisan::call('cache:clear');
                    break;
                case 'config':
                    Artisan::call('config:clear');
                    break;
                case 'route':
                    Artisan::call('route:clear');
                    break;
                case 'view':
                    Artisan::call('view:clear');
                    break;
                case 'compiled':
                    Artisan::call('clear-compiled');
                    break;
                case 'all':
                default:
                    Artisan::call('cache:clear');
                    Artisan::call('config:clear');
                    Artisan::call('route:clear');
                    Artisan::call('view:clear');
                    Artisan::call('clear-compiled');
                    break;
            }

            return response()->json([
                'success' => true,
                'message' => ucfirst($type) . ' cache cleared successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error clearing cache', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cache',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Optimize database.
     */
    public function optimizeDatabase(Request $request)
    {
        try {
            // Get database name
            $databaseName = DB::connection()->getDatabaseName();
            
            // Get table sizes before optimization
            $tablesBefore = $this->getTableSizes($databaseName);
            
            // Log the action
            AuditLog::create([
                'user_id' => $request->user()->id,
                'action' => 'database_optimized',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'details' => [
                    'database' => $databaseName,
                    'optimized_at' => now()->toISOString(),
                ],
            ]);

            // Optimize tables
            $tables = DB::select('SHOW TABLES');
            $optimizedTables = [];
            
            foreach ($tables as $table) {
                $tableName = array_values((array)$table)[0];
                try {
                    DB::statement("OPTIMIZE TABLE `{$tableName}`");
                    $optimizedTables[] = $tableName;
                } catch (\Exception $e) {
                    Log::warning("Failed to optimize table: {$tableName}", ['error' => $e->getMessage()]);
                }
            }

            // Get table sizes after optimization
            $tablesAfter = $this->getTableSizes($databaseName);

            return response()->json([
                'success' => true,
                'message' => 'Database optimized successfully',
                'data' => [
                    'optimized_tables' => $optimizedTables,
                    'tables_count' => count($optimizedTables),
                    'tables_before' => $tablesBefore,
                    'tables_after' => $tablesAfter,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error optimizing database', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to optimize database',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get cache size (approximate).
     */
    private function getCacheSize(): string
    {
        try {
            $cacheDir = storage_path('framework/cache');
            if (is_dir($cacheDir)) {
                $size = 0;
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($cacheDir)
                );
                foreach ($files as $file) {
                    if ($file->isFile()) {
                        $size += $file->getSize();
                    }
                }
                return $this->formatBytes($size);
            }
            return '0 B';
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    /**
     * Format bytes to human-readable format.
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Get table sizes.
     */
    private function getTableSizes(string $databaseName): array
    {
        try {
            $tables = DB::select("
                SELECT 
                    table_name AS name,
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
                FROM information_schema.tables 
                WHERE table_schema = ?
                ORDER BY (data_length + index_length) DESC
            ", [$databaseName]);

            return array_map(function ($table) {
                return [
                    'name' => $table->name,
                    'size_mb' => (float)$table->size_mb,
                ];
            }, $tables);
        } catch (\Exception $e) {
            Log::error('Error getting table sizes', ['error' => $e->getMessage()]);
            return [];
        }
    }
}

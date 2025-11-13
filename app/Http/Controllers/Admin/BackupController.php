<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupHistory;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    /**
     * Get backup history with pagination.
     */
    public function index(Request $request)
    {
        $query = BackupHistory::with('createdBy')->orderBy('created_at', 'desc');

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->has('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        $backups = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'backups' => $backups,
        ]);
    }

    /**
     * Create a database backup.
     */
    public function create(Request $request)
    {
        try {
            $user = Auth::user();
            $databaseName = config('database.connections.mysql.database');
            $backupDir = storage_path('app/backups');
            
            // Create backups directory if it doesn't exist
            if (!file_exists($backupDir)) {
                mkdir($backupDir, 0755, true);
            }

            $timestamp = now()->format('Y-m-d_H-i-s');
            $filename = "backup_{$databaseName}_{$timestamp}.sql";
            $filePath = $backupDir . '/' . $filename;

            // Create backup history record
            // Ensure we're using the correct model instance
            $backupHistoryModel = new BackupHistory();
            Log::info('BackupHistory table name: ' . $backupHistoryModel->getTable());
            
            $backupHistory = BackupHistory::create([
                'filename' => $filename,
                'file_path' => $filePath,
                'type' => 'manual',
                'status' => 'in_progress',
                'created_by' => $user->id,
            ]);

            // Get database credentials
            $dbHost = config('database.connections.mysql.host');
            $dbUser = config('database.connections.mysql.username');
            $dbPass = config('database.connections.mysql.password');
            $dbName = config('database.connections.mysql.database');

            // Create mysqldump command
            // Use -p flag with password (no space between -p and password)
            // Password is properly escaped with escapeshellarg
            $command = sprintf(
                'mysqldump -h %s -u %s -p%s %s > %s 2>&1',
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($filePath)
            );

            // Execute backup in background
            exec($command, $output, $returnVar);

            if ($returnVar === 0 && file_exists($filePath)) {
                $fileSize = filesize($filePath);
                $fileSizeFormatted = $this->formatBytes($fileSize);

                $backupHistory->update([
                    'status' => 'completed',
                    'file_size' => $fileSizeFormatted,
                    'file_size_bytes' => $fileSize,
                    'completed_at' => now(),
                ]);

                // Log action
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'created_database_backup',
                    'description' => "Created database backup: {$filename}",
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Database backup created successfully.',
                    'backup' => $backupHistory->load('createdBy'),
                ]);
            } else {
                $errorMessage = implode("\n", $output);
                $backupHistory->update([
                    'status' => 'failed',
                    'error_message' => $errorMessage ?: 'Backup command failed',
                    'completed_at' => now(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create database backup.',
                    'error' => $errorMessage ?: 'Unknown error',
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Error creating backup', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to create database backup.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Restore database from backup.
     */
    public function restore(Request $request, $id)
    {
        try {
            $user = Auth::user();
            $backup = BackupHistory::findOrFail($id);

            if ($backup->status !== 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot restore from a backup that is not completed.',
                ], 400);
            }

            if (!file_exists($backup->file_path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Backup file not found.',
                ], 404);
            }

            // Get database credentials
            $dbHost = config('database.connections.mysql.host');
            $dbUser = config('database.connections.mysql.username');
            $dbPass = config('database.connections.mysql.password');
            $dbName = config('database.connections.mysql.database');

            // Create mysql restore command
            // Use -p flag with password (no space between -p and password)
            // Password is properly escaped with escapeshellarg
            $command = sprintf(
                'mysql -h %s -u %s -p%s %s < %s 2>&1',
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($backup->file_path)
            );

            exec($command, $output, $returnVar);

            if ($returnVar === 0) {
                // Log action
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'restored_database_backup',
                    'description' => "Restored database from backup: {$backup->filename}",
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Database restored successfully.',
                ]);
            } else {
                $errorMessage = implode("\n", $output);
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to restore database.',
                    'error' => $errorMessage ?: 'Unknown error',
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Error restoring backup', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to restore database.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download backup file.
     */
    public function download($id)
    {
        $backup = BackupHistory::findOrFail($id);

        if (!file_exists($backup->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'Backup file not found.',
            ], 404);
        }

        return response()->download($backup->file_path, $backup->filename);
    }

    /**
     * Delete backup.
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = Auth::user();
            $backup = BackupHistory::findOrFail($id);

            // Delete file if exists
            if (file_exists($backup->file_path)) {
                unlink($backup->file_path);
            }

            $filename = $backup->filename;
            $backup->delete();

            // Log action
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'deleted_database_backup',
                'description' => "Deleted database backup: {$filename}",
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Backup deleted successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting backup', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete backup.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Format bytes to human readable format.
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatHistory;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminChatController extends Controller
{
    /**
     * Get all chats with pagination, search, and filtering.
     */
    public function index(Request $request)
    {
        $query = ChatHistory::with(['user', 'flaggedBy', 'reviewedBy']);

        // Filter by flagged status
        if ($request->has('flagged') && $request->flagged !== 'all') {
            $query->where('flagged', $request->flagged === 'true');
        }

        // Filter by reviewed status
        if ($request->has('reviewed') && $request->reviewed !== 'all') {
            $query->where('reviewed', $request->reviewed === 'true');
        }

        // Filter by user
        if ($request->has('user_id') && $request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by session
        if ($request->has('session_id') && $request->session_id) {
            $query->where('session_id', $request->session_id);
        }

        // Search by message or response
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('message', 'like', '%' . $search . '%')
                  ->orWhere('response', 'like', '%' . $search . '%');
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

        $chats = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'chats' => $chats,
        ]);
    }

    /**
     * Get chat analytics.
     */
    public function analytics(Request $request)
    {
        // Parse date range with proper time boundaries
        // Default to last 90 days to include more data, but allow custom range
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');
        
        $query = ChatHistory::query();
        
        // Apply date range filter only if dates are provided
        if ($dateFrom && $dateTo) {
            $startDate = \Carbon\Carbon::parse($dateFrom)->startOfDay();
            $endDate = \Carbon\Carbon::parse($dateTo)->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
        } else {
            // If no date range provided, default to last 90 days
            $startDate = now()->subDays(90)->startOfDay();
            $endDate = now()->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        // Total chats
        $totalChats = (clone $query)->count();

        // Total unique users
        $totalUsers = (clone $query)->distinct('user_id')->count('user_id');

        // Total unique sessions
        $totalSessions = (clone $query)->distinct('session_id')->count('session_id');

        // Average response time (this would require tracking, for now we'll use a placeholder)
        $avgResponseTime = 0; // TODO: Implement response time tracking

        // Chats per day
        $chatsPerDay = (clone $query)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'count' => (int)$item->count,
                ];
            })
            ->values()
            ->toArray();

        // Chats by user
        $chatsByUser = (clone $query)
            ->selectRaw('user_id, COUNT(*) as count')
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
                ];
            })
            ->values()
            ->toArray();

        // Flagged chats
        $flaggedChats = (clone $query)->where('flagged', true)->count();

        // Reviewed chats
        $reviewedChats = (clone $query)->where('reviewed', true)->count();

        // Most active sessions
        $mostActiveSessions = (clone $query)
            ->selectRaw('session_id, COUNT(*) as count, MAX(created_at) as last_message_at')
            ->groupBy('session_id')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                return [
                    'session_id' => $item->session_id,
                    'message_count' => (int)$item->count,
                    'last_message_at' => $item->last_message_at,
                ];
            })
            ->values()
            ->toArray();

        // Popular topics (extract keywords from messages - simple implementation)
        $popularTopics = (clone $query)
            ->select('message')
            ->get()
            ->pluck('message')
            ->flatMap(function ($message) {
                // Simple keyword extraction (first few words)
                $words = explode(' ', strtolower($message));
                return array_slice($words, 0, 3);
            })
            ->filter(function ($word) {
                // Filter out common words
                $commonWords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could', 'should', 'may', 'might', 'must', 'can'];
                return strlen($word) > 3 && !in_array($word, $commonWords);
            })
            ->countBy()
            ->sortDesc()
            ->take(10)
            ->map(function ($count, $word) {
                return [
                    'topic' => $word,
                    'count' => $count,
                ];
            })
            ->values()
            ->toArray();

        return response()->json([
            'success' => true,
            'analytics' => [
                'total_chats' => $totalChats,
                'total_users' => $totalUsers,
                'total_sessions' => $totalSessions,
                'avg_response_time' => $avgResponseTime,
                'flagged_chats' => $flaggedChats,
                'reviewed_chats' => $reviewedChats,
                'chats_per_day' => $chatsPerDay,
                'chats_by_user' => $chatsByUser,
                'most_active_sessions' => $mostActiveSessions,
                'popular_topics' => $popularTopics,
                'date_from' => isset($startDate) ? $startDate->toDateString() : ($dateFrom ?? now()->subDays(90)->toDateString()),
                'date_to' => isset($endDate) ? $endDate->toDateString() : ($dateTo ?? now()->toDateString()),
            ],
        ]);
    }

    /**
     * Get a specific chat.
     */
    public function show(ChatHistory $chatHistory)
    {
        $chatHistory->load(['user', 'flaggedBy', 'reviewedBy']);
        return response()->json([
            'success' => true,
            'chat' => $chatHistory,
        ]);
    }

    /**
     * Delete a chat.
     */
    public function destroy(Request $request, ChatHistory $chatHistory)
    {
        $chatData = $chatHistory->toArray();
        $chatHistory->delete();

        // Log the action
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'admin_delete_chat',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => [
                'chat_id' => $chatData['id'],
                'user_id' => $chatData['user_id'],
                'session_id' => $chatData['session_id'] ?? null,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Chat deleted successfully',
        ]);
    }

    /**
     * Bulk delete chats.
     */
    public function bulkDelete(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'chat_ids' => 'required|array',
            'chat_ids.*' => 'required|integer|exists:chat_history,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $chatIds = $request->chat_ids;
        $deletedCount = ChatHistory::whereIn('id', $chatIds)->delete();

        // Log the action
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'admin_bulk_delete_chats',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => [
                'deleted_count' => $deletedCount,
                'chat_ids' => $chatIds,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Chats deleted successfully',
            'deleted_count' => $deletedCount,
        ]);
    }

    /**
     * Flag a chat for moderation.
     */
    public function flagChat(Request $request, ChatHistory $chatHistory)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $chatHistory->flagged = true;
        $chatHistory->flagged_by = Auth::id();
        $chatHistory->flagged_at = now();
        $chatHistory->flag_reason = $request->reason;
        $chatHistory->save();

        // Log the action
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'admin_flag_chat',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => [
                'chat_id' => $chatHistory->id,
                'reason' => $request->reason,
            ],
        ]);

        $chatHistory->load(['user', 'flaggedBy', 'reviewedBy']);
        return response()->json([
            'success' => true,
            'message' => 'Chat flagged successfully',
            'chat' => $chatHistory,
        ]);
    }

    /**
     * Unflag a chat.
     */
    public function unflagChat(Request $request, ChatHistory $chatHistory)
    {
        $chatHistory->flagged = false;
        $chatHistory->flagged_by = null;
        $chatHistory->flagged_at = null;
        $chatHistory->flag_reason = null;
        $chatHistory->save();

        // Log the action
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'admin_unflag_chat',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => [
                'chat_id' => $chatHistory->id,
            ],
        ]);

        $chatHistory->load(['user', 'flaggedBy', 'reviewedBy']);
        return response()->json([
            'success' => true,
            'message' => 'Chat unflagged successfully',
            'chat' => $chatHistory,
        ]);
    }

    /**
     * Review a chat (mark as reviewed).
     */
    public function reviewChat(Request $request, ChatHistory $chatHistory)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'nullable|string|in:approve,reject',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $chatHistory->reviewed = true;
        $chatHistory->reviewed_by = Auth::id();
        $chatHistory->reviewed_at = now();
        
        // If action is reject, also flag it
        if ($request->action === 'reject') {
            $chatHistory->flagged = true;
            $chatHistory->flagged_by = Auth::id();
            $chatHistory->flagged_at = now();
            if ($request->notes) {
                $chatHistory->flag_reason = $request->notes;
            }
        }
        
        $chatHistory->save();

        // Log the action
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'admin_review_chat',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => [
                'chat_id' => $chatHistory->id,
                'action' => $request->action,
                'notes' => $request->notes,
            ],
        ]);

        $chatHistory->load(['user', 'flaggedBy', 'reviewedBy']);
        return response()->json([
            'success' => true,
            'message' => 'Chat reviewed successfully',
            'chat' => $chatHistory,
        ]);
    }

    /**
     * Export chats to CSV.
     */
    public function export(Request $request)
    {
        $query = ChatHistory::with(['user', 'flaggedBy', 'reviewedBy']);

        // Apply filters (same as index method)
        if ($request->has('flagged') && $request->flagged !== 'all') {
            $query->where('flagged', $request->flagged === 'true');
        }
        if ($request->has('reviewed') && $request->reviewed !== 'all') {
            $query->where('reviewed', $request->reviewed === 'true');
        }
        if ($request->has('user_id') && $request->user_id) {
            $query->where('user_id', $request->user_id);
        }
        if ($request->has('session_id') && $request->session_id) {
            $query->where('session_id', $request->session_id);
        }
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('message', 'like', '%' . $search . '%')
                  ->orWhere('response', 'like', '%' . $search . '%');
            });
        }
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $chats = $query->orderBy('created_at', 'desc')->get();

        $filename = 'chats_export_' . now()->format('Y-m-d_H-i-s') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($chats) {
            $file = fopen('php://output', 'w');
            fputcsv($file, [
                'ID', 'User', 'Username', 'Session ID', 'Message', 'Response',
                'Flagged', 'Reviewed', 'Flagged By', 'Reviewed By',
                'Flagged At', 'Reviewed At', 'Flag Reason', 'Created At'
            ]);

            foreach ($chats as $chat) {
                fputcsv($file, [
                    $chat->id,
                    $chat->user->name ?? 'N/A',
                    $chat->user->username ?? 'N/A',
                    $chat->session_id ?? 'N/A',
                    $chat->message,
                    $chat->response,
                    $chat->flagged ? 'Yes' : 'No',
                    $chat->reviewed ? 'Yes' : 'No',
                    $chat->flaggedBy->username ?? 'N/A',
                    $chat->reviewedBy->username ?? 'N/A',
                    $chat->flagged_at ? $chat->flagged_at->format('Y-m-d H:i:s') : 'N/A',
                    $chat->reviewed_at ? $chat->reviewed_at->format('Y-m-d H:i:s') : 'N/A',
                    $chat->flag_reason ?? 'N/A',
                    $chat->created_at->format('Y-m-d H:i:s'),
                ]);
            }
            fclose($file);
        };

        // Log the action
        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'admin_export_chats',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => [
                'export_count' => $chats->count(),
            ],
        ]);

        return new StreamedResponse($callback, 200, $headers);
    }

    /**
     * Get chat history for a specific user.
     */
    public function getUserChats(Request $request, User $user)
    {
        $query = ChatHistory::where('user_id', $user->id)->with(['user', 'flaggedBy', 'reviewedBy']);

        // Filter by session
        if ($request->has('session_id') && $request->session_id) {
            $query->where('session_id', $request->session_id);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('message', 'like', '%' . $search . '%')
                  ->orWhere('response', 'like', '%' . $search . '%');
            });
        }

        $perPage = $request->get('per_page', 20);
        $perPage = min(max((int)$perPage, 1), 100);

        $chats = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'chats' => $chats,
            'user' => $user,
        ]);
    }
}

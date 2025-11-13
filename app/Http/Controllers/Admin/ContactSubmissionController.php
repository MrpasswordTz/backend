<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactSubmission;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ContactSubmissionController extends Controller
{
    /**
     * Get all contact submissions with pagination and filtering.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $status = $request->get('status', 'all'); // all, read, unread, replied
            $search = $request->get('search', '');

            $query = ContactSubmission::with('repliedBy:id,username,name')
                ->orderBy('created_at', 'desc');

            // Filter by status
            if ($status === 'read') {
                $query->where('read', true);
            } elseif ($status === 'unread') {
                $query->where('read', false);
            } elseif ($status === 'replied') {
                $query->where('replied', true);
            }

            // Search
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%")
                        ->orWhere('message', 'like', "%{$search}%");
                });
            }

            $submissions = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'submissions' => $submissions,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching contact submissions', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contact submissions.',
            ], 500);
        }
    }

    /**
     * Get a single contact submission.
     */
    public function show($id)
    {
        try {
            $submission = ContactSubmission::with('repliedBy:id,username,name')->findOrFail($id);

            return response()->json([
                'success' => true,
                'submission' => $submission,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Contact submission not found.',
            ], 404);
        }
    }

    /**
     * Mark submission as read/unread.
     */
    public function markRead(Request $request, $id)
    {
        try {
            $submission = ContactSubmission::findOrFail($id);
            $read = $request->get('read', true);

            $submission->update(['read' => $read]);

            // Log action
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => $read ? 'marked_contact_submission_read' : 'marked_contact_submission_unread',
                'ip_address' => $request->ip(),
                'details' => json_encode([
                    'submission_id' => $id,
                    'submission_email' => $submission->email,
                ]),
            ]);

            return response()->json([
                'success' => true,
                'message' => $read ? 'Submission marked as read.' : 'Submission marked as unread.',
                'submission' => $submission->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error marking contact submission as read', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update submission status.',
            ], 500);
        }
    }

    /**
     * Reply to a contact submission.
     */
    public function reply(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'reply_message' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $submission = ContactSubmission::findOrFail($id);

            $submission->update([
                'replied' => true,
                'reply_message' => $request->reply_message,
                'replied_by' => auth()->id(),
                'replied_at' => now(),
                'read' => true, // Also mark as read when replying
            ]);

            // Log action
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'replied_to_contact_submission',
                'ip_address' => $request->ip(),
                'details' => json_encode([
                    'submission_id' => $id,
                    'submission_email' => $submission->email,
                ]),
            ]);

            // TODO: Send email notification to the user
            // Mail::to($submission->email)->send(new ContactReply($submission, $request->reply_message));

            return response()->json([
                'success' => true,
                'message' => 'Reply sent successfully.',
                'submission' => $submission->fresh()->load('repliedBy:id,username,name'),
            ]);
        } catch (\Exception $e) {
            Log::error('Error replying to contact submission', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to send reply.',
            ], 500);
        }
    }

    /**
     * Delete a contact submission.
     */
    public function destroy($id)
    {
        try {
            $submission = ContactSubmission::findOrFail($id);
            $email = $submission->email;
            $submission->delete();

            // Log action
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'deleted_contact_submission',
                'ip_address' => request()->ip(),
                'details' => json_encode([
                    'submission_id' => $id,
                    'submission_email' => $email,
                ]),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Contact submission deleted successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting contact submission', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete submission.',
            ], 500);
        }
    }

    /**
     * Export contact submissions to CSV.
     */
    public function export(Request $request)
    {
        try {
            $status = $request->get('status', 'all');
            $search = $request->get('search', '');

            $query = ContactSubmission::with('repliedBy:id,username,name')
                ->orderBy('created_at', 'desc');

            // Apply filters
            if ($status === 'read') {
                $query->where('read', true);
            } elseif ($status === 'unread') {
                $query->where('read', false);
            } elseif ($status === 'replied') {
                $query->where('replied', true);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            }

            $submissions = $query->get();

            // Generate CSV
            $filename = 'contact_submissions_' . date('Y-m-d_His') . '.csv';
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];

            $callback = function () use ($submissions) {
                $file = fopen('php://output', 'w');
                
                // CSV headers
                fputcsv($file, [
                    'ID',
                    'Name',
                    'Email',
                    'Subject',
                    'Message',
                    'IP Address',
                    'Read',
                    'Replied',
                    'Reply Message',
                    'Replied By',
                    'Replied At',
                    'Created At',
                ]);

                foreach ($submissions as $submission) {
                    fputcsv($file, [
                        $submission->id,
                        $submission->name,
                        $submission->email,
                        $submission->subject,
                        $submission->message,
                        $submission->ip_address,
                        $submission->read ? 'Yes' : 'No',
                        $submission->replied ? 'Yes' : 'No',
                        $submission->reply_message ?? '',
                        $submission->repliedBy ? $submission->repliedBy->username : '',
                        $submission->replied_at ? $submission->replied_at->format('Y-m-d H:i:s') : '',
                        $submission->created_at->format('Y-m-d H:i:s'),
                    ]);
                }

                fclose($file);
            };

            // Log action
            AuditLog::create([
                'user_id' => auth()->id(),
                'action' => 'exported_contact_submissions',
                'ip_address' => request()->ip(),
                'details' => json_encode([
                    'status' => $status,
                    'search' => $search,
                    'count' => $submissions->count(),
                ]),
            ]);

            return response()->stream($callback, 200, $headers);
        } catch (\Exception $e) {
            Log::error('Error exporting contact submissions', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export submissions.',
            ], 500);
        }
    }
}

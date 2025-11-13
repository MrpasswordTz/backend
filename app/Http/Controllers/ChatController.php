<?php

namespace App\Http\Controllers;

use App\Models\ChatHistory;
use App\Models\AuditLog;
use App\Models\ApiUsageLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    /**
     * Send a chat message to AI.
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'session_id' => 'nullable|string',
        ]);

        $user = $request->user();
        $message = $request->message;
        $sessionId = $request->session_id ?? uniqid('session_', true);

        try {
            $responseText = null;
            $apiUsed = false;

            // Get Hugging Face token from .env
            $hfToken = env('HF_TOKEN');
            // Default model: DeepHat/DeepHat-V1-7B:featherless-ai (keep the full model name with suffix)
            $hfModel = env('HF_MODEL', 'DeepHat/DeepHat-V1-7B:featherless-ai');
            $hfApiUrl = env('HF_API_URL', 'https://router.huggingface.co/v1/chat/completions');

            // Try Hugging Face API first (primary method)
            if ($hfToken) {
                try {
                    $startTime = microtime(true);
                    $apiProvider = 'huggingface';
                    $apiEndpoint = $hfApiUrl;
                    $requestData = [
                        'model' => $hfModel,
                        'messages' => [
                            [
                                'role' => 'user',
                                'content' => $message,
                            ],
                        ],
                        'stream' => false,
                        'temperature' => 0.7,
                        'max_tokens' => 500,
                    ];
                    
                    Log::info('Attempting Hugging Face API call', [
                        'model' => $hfModel,
                        'url' => $hfApiUrl,
                    ]);

                    // Try the router endpoint (OpenAI-compatible format)
                    // Increased timeout to 90 seconds as HF API can be slow
                    $response = Http::timeout(90)->retry(1, 2000)->withHeaders([
                        'Authorization' => 'Bearer ' . $hfToken,
                        'Content-Type' => 'application/json',
                    ])->post($hfApiUrl, $requestData);
                    
                    $endTime = microtime(true);
                    $responseTime = round(($endTime - $startTime) * 1000); // Convert to milliseconds
                    $statusCode = $response->status();
                    $success = $response->successful();
                    $responseData = $response->json();
                    
                    // Extract token usage from response
                    $inputTokens = 0;
                    $outputTokens = 0;
                    $totalTokens = 0;
                    
                    if (isset($responseData['usage'])) {
                        $inputTokens = $responseData['usage']['prompt_tokens'] ?? 0;
                        $outputTokens = $responseData['usage']['completion_tokens'] ?? 0;
                        $totalTokens = $responseData['usage']['total_tokens'] ?? 0;
                    }

                    if ($success) {
                        if (isset($responseData['choices'][0]['message']['content'])) {
                            $responseText = $responseData['choices'][0]['message']['content'];
                            $apiUsed = true;
                            Log::info('Hugging Face API success', [
                                'model' => $hfModel,
                                'message_length' => strlen($message),
                            ]);
                        } else {
                            Log::warning('Hugging Face API unexpected response format', [
                                'response' => $responseData,
                            ]);
                        }
                    } else {
                        $body = $response->body();
                        
                        // Log detailed error information
                        Log::warning('Hugging Face API request failed', [
                            'status' => $statusCode,
                            'model' => $hfModel,
                            'response_preview' => substr($body, 0, 500),
                        ]);

                        // If 503 (Service Unavailable) or 429 (Rate Limit), wait and retry once
                        if ($statusCode === 503 || $statusCode === 429) {
                            Log::info('Hugging Face API temporarily unavailable, waiting before retry', [
                                'status' => $statusCode,
                            ]);
                            // Wait 3 seconds before retry
                            sleep(3);
                            
                            try {
                                $retryStartTime = microtime(true);
                                $retryResponse = Http::timeout(90)->withHeaders([
                                    'Authorization' => 'Bearer ' . $hfToken,
                                    'Content-Type' => 'application/json',
                                ])->post($hfApiUrl, $requestData);
                                
                                $retryEndTime = microtime(true);
                                $retryResponseTime = round(($retryEndTime - $retryStartTime) * 1000);
                                $retryStatusCode = $retryResponse->status();
                                $retrySuccess = $retryResponse->successful();
                                $retryData = $retryResponse->json();
                                
                                // Extract token usage from retry response
                                $retryInputTokens = 0;
                                $retryOutputTokens = 0;
                                $retryTotalTokens = 0;
                                
                                if (isset($retryData['usage'])) {
                                    $retryInputTokens = $retryData['usage']['prompt_tokens'] ?? 0;
                                    $retryOutputTokens = $retryData['usage']['completion_tokens'] ?? 0;
                                    $retryTotalTokens = $retryData['usage']['total_tokens'] ?? 0;
                                }

                                if ($retrySuccess) {
                                    if (isset($retryData['choices'][0]['message']['content'])) {
                                        $responseText = $retryData['choices'][0]['message']['content'];
                                        $apiUsed = true;
                                        Log::info('Hugging Face API retry successful');
                                        
                                        // Update response data for logging (retry was successful)
                                        $responseTime = $retryResponseTime;
                                        $statusCode = $retryStatusCode;
                                        $success = $retrySuccess;
                                        $responseData = $retryData;
                                        $inputTokens = $retryInputTokens;
                                        $outputTokens = $retryOutputTokens;
                                        $totalTokens = $retryTotalTokens;
                                    }
                                } else {
                                    // Retry also failed - use retry data for logging
                                    $responseTime = $retryResponseTime;
                                    $statusCode = $retryStatusCode;
                                    $success = false;
                                    $responseData = $retryData;
                                    $inputTokens = $retryInputTokens;
                                    $outputTokens = $retryOutputTokens;
                                    $totalTokens = $retryTotalTokens;
                                }
                            } catch (\Exception $retryError) {
                                Log::warning('Hugging Face API retry failed', [
                                    'error' => $retryError->getMessage(),
                                ]);
                            }
                        }
                    }
                    
                    // Log API usage once at the end (for both success and failure)
                    // Only log if we have response data or if it was an error
                    if (isset($responseData) || !$success) {
                        $errorMessage = null;
                        if (!$success) {
                            if (isset($retryResponse)) {
                                $errorMessage = $retryResponse->body() ?? 'Unknown error';
                            } else {
                                $errorMessage = $response->body() ?? 'Unknown error';
                            }
                        }
                        
                        $this->logApiUsage([
                            'api_provider' => $apiProvider,
                            'endpoint' => $apiEndpoint,
                            'user_id' => $user->id,
                            'model' => $hfModel,
                            'input_tokens' => $inputTokens,
                            'output_tokens' => $outputTokens,
                            'total_tokens' => $totalTokens,
                            'response_time_ms' => $responseTime,
                            'status_code' => $statusCode,
                            'success' => $success,
                            'error_message' => $errorMessage,
                            'request_data' => json_encode($requestData),
                            'response_data' => json_encode(substr(json_encode($responseData ?? []), 0, 1000)),
                            'ip_address' => $request->ip(),
                            'user_agent' => $request->userAgent(),
                            'cost' => null, // Hugging Face is free, but could calculate cost if needed
                        ]);
                    }
                } catch (\Exception $hfError) {
                    Log::error('Hugging Face API error', [
                        'error' => $hfError->getMessage(),
                        'trace' => substr($hfError->getTraceAsString(), 0, 500),
                    ]);
                    
                    // Log the error
                    $this->logApiUsage([
                        'api_provider' => 'huggingface',
                        'endpoint' => $hfApiUrl,
                        'user_id' => $user->id,
                        'model' => $hfModel,
                        'input_tokens' => 0,
                        'output_tokens' => 0,
                        'total_tokens' => 0,
                        'response_time_ms' => null,
                        'status_code' => null,
                        'success' => false,
                        'error_message' => $hfError->getMessage(),
                        'request_data' => json_encode(['model' => $hfModel, 'message' => substr($message, 0, 100)]),
                        'response_data' => null,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'cost' => null,
                    ]);
                }
            }

            // Fallback: Try OpenAI-compatible API if configured and HF failed
            if (!$apiUsed && env('OPENAI_API_KEY')) {
                try {
                    $openaiStartTime = microtime(true);
                    $openaiKey = env('OPENAI_API_KEY');
                    $openaiUrl = env('AI_API_URL', 'https://api.openai.com/v1/chat/completions');
                    $openaiModel = env('AI_MODEL', 'gpt-3.5-turbo');
                    $openaiRequestData = [
                        'model' => $openaiModel,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'You are a helpful AI assistant. Provide clear and concise responses.',
                            ],
                            [
                                'role' => 'user',
                                'content' => $message,
                            ],
                        ],
                        'stream' => false,
                        'max_tokens' => 500,
                        'temperature' => 0.7,
                    ];

                    $openaiResponse = Http::timeout(30)->withHeaders([
                        'Authorization' => 'Bearer ' . $openaiKey,
                        'Content-Type' => 'application/json',
                    ])->post($openaiUrl, $openaiRequestData);
                    
                    $openaiEndTime = microtime(true);
                    $openaiResponseTime = round(($openaiEndTime - $openaiStartTime) * 1000);
                    $openaiStatusCode = $openaiResponse->status();
                    $openaiSuccess = $openaiResponse->successful();
                    $openaiData = $openaiResponse->json();
                    
                    // Extract token usage from OpenAI response
                    $openaiInputTokens = 0;
                    $openaiOutputTokens = 0;
                    $openaiTotalTokens = 0;
                    $openaiCost = null;
                    
                    if (isset($openaiData['usage'])) {
                        $openaiInputTokens = $openaiData['usage']['prompt_tokens'] ?? 0;
                        $openaiOutputTokens = $openaiData['usage']['completion_tokens'] ?? 0;
                        $openaiTotalTokens = $openaiData['usage']['total_tokens'] ?? 0;
                        
                        // Calculate cost (approximate pricing for GPT-3.5-turbo)
                        // Input: $0.0015 per 1K tokens, Output: $0.002 per 1K tokens
                        $inputCost = ($openaiInputTokens / 1000) * 0.0015;
                        $outputCost = ($openaiOutputTokens / 1000) * 0.002;
                        $openaiCost = $inputCost + $outputCost;
                    }

                    if ($openaiSuccess) {
                        if (isset($openaiData['choices'][0]['message']['content'])) {
                            $responseText = $openaiData['choices'][0]['message']['content'];
                            $apiUsed = true;
                            Log::info('OpenAI API used as fallback');
                        }
                    }
                    
                    // Log OpenAI API usage
                    $this->logApiUsage([
                        'api_provider' => 'openai',
                        'endpoint' => $openaiUrl,
                        'user_id' => $user->id,
                        'model' => $openaiModel,
                        'input_tokens' => $openaiInputTokens,
                        'output_tokens' => $openaiOutputTokens,
                        'total_tokens' => $openaiTotalTokens,
                        'response_time_ms' => $openaiResponseTime,
                        'status_code' => $openaiStatusCode,
                        'success' => $openaiSuccess,
                        'error_message' => $openaiSuccess ? null : ($openaiResponse->body() ?? 'Unknown error'),
                        'request_data' => json_encode($openaiRequestData),
                        'response_data' => json_encode(substr(json_encode($openaiData ?? []), 0, 1000)),
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'cost' => $openaiCost,
                    ]);
                } catch (\Exception $openaiError) {
                    Log::info('OpenAI API error', [
                        'error' => $openaiError->getMessage(),
                    ]);
                    
                    // Log the error
                    $this->logApiUsage([
                        'api_provider' => 'openai',
                        'endpoint' => env('AI_API_URL', 'https://api.openai.com/v1/chat/completions'),
                        'user_id' => $user->id,
                        'model' => env('AI_MODEL', 'gpt-3.5-turbo'),
                        'input_tokens' => 0,
                        'output_tokens' => 0,
                        'total_tokens' => 0,
                        'response_time_ms' => null,
                        'status_code' => null,
                        'success' => false,
                        'error_message' => $openaiError->getMessage(),
                        'request_data' => json_encode(['message' => substr($message, 0, 100)]),
                        'response_data' => null,
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'cost' => null,
                    ]);
                }
            }

            // Final fallback: Demo mode response if all APIs failed
            if (!$apiUsed) {
                $fallbackResponses = [
                    "I understand you said: \"" . substr($message, 0, 100) . "\". That's an interesting question! Unfortunately, the AI service is currently unavailable. Please try again in a few moments.",
                    "Thank you for your message! Your message was received: \"" . substr($message, 0, 100) . "\". The AI service is temporarily unavailable. Please try again later.",
                    "I received your message: \"" . substr($message, 0, 100) . "\". The AI service is experiencing issues right now. Please try again in a few moments.",
                ];
                
                $responseText = $fallbackResponses[array_rand($fallbackResponses)];
                
                // Simple echo for short messages
                if (strlen($message) < 50) {
                    $responseText = "You said: \"" . $message . "\". The AI service is temporarily unavailable. Please try again later.";
                }

                Log::warning('Using fallback response - all AI APIs failed', [
                    'hf_token_set' => !empty($hfToken),
                    'openai_key_set' => !empty(env('OPENAI_API_KEY')),
                ]);
            }

            // Save chat history
            $chatHistory = ChatHistory::create([
                'user_id' => $user->id,
                'message' => $message,
                'response' => $responseText,
                'session_id' => $sessionId,
            ]);

            // Log the chat (only log errors, not warnings for demo mode)
            try {
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'chat_message',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'details' => [
                        'session_id' => $sessionId,
                        'message_length' => strlen($message),
                        'api_used' => $apiUsed,
                    ],
                ]);
            } catch (\Exception $logError) {
                // Don't fail if logging fails
                Log::warning('Failed to log chat message', [
                    'error' => $logError->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'response' => $responseText,
                'session_id' => $sessionId,
                'id' => $chatHistory->id,
            ], 200);

        } catch (\Exception $e) {
            Log::error('Chat Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while processing your request: ' . $e->getMessage(),
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
    
    /**
     * Log API usage to database.
     */
    private function logApiUsage(array $data)
    {
        try {
            ApiUsageLog::create($data);
        } catch (\Exception $e) {
            // Don't fail if logging fails, but log the error
            Log::warning('Failed to log API usage', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
        }
    }

    /**
     * Get user's chat history.
     */
    public function getChatHistory(Request $request)
    {
        $user = $request->user();
        $sessionId = $request->query('session_id');

        $query = ChatHistory::where('user_id', $user->id);

        if ($sessionId) {
            $query->where('session_id', $sessionId);
        }

        $chatHistory = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'chat_history' => $chatHistory,
        ]);
    }

    /**
     * Get user's chat sessions.
     */
    public function getChatSessions(Request $request)
    {
        $user = $request->user();

        $sessions = ChatHistory::where('user_id', $user->id)
            ->select('session_id')
            ->distinct()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($item) use ($user) {
                $lastMessage = ChatHistory::where('user_id', $user->id)
                    ->where('session_id', $item->session_id)
                    ->orderBy('created_at', 'desc')
                    ->first();

                return [
                    'session_id' => $item->session_id,
                    'last_message' => $lastMessage->message ?? '',
                    'last_message_at' => $lastMessage->created_at ?? null,
                    'message_count' => ChatHistory::where('user_id', $user->id)
                        ->where('session_id', $item->session_id)
                        ->count(),
                ];
            });

        return response()->json([
            'success' => true,
            'sessions' => $sessions,
        ]);
    }

    /**
     * Delete chat history.
     */
    public function deleteChatHistory(Request $request, $id = null)
    {
        $user = $request->user();
        $sessionId = $request->input('session_id');

        if ($id) {
            // Delete specific chat history
            $chatHistory = ChatHistory::where('id', $id)
                ->where('user_id', $user->id)
                ->first();

            if (!$chatHistory) {
                return response()->json([
                    'success' => false,
                    'message' => 'Chat history not found',
                ], 404);
            }

            $chatHistory->delete();
        } elseif ($sessionId) {
            // Delete all chat history for a session
            ChatHistory::where('user_id', $user->id)
                ->where('session_id', $sessionId)
                ->delete();
        } else {
            // Delete all chat history for user
            ChatHistory::where('user_id', $user->id)->delete();
        }

        // Log the deletion
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'delete_chat_history',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'details' => [
                'chat_history_id' => $id,
                'session_id' => $sessionId,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Chat history deleted successfully',
        ]);
    }
}


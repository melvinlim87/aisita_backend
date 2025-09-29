<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatController extends Controller
{
    /**
     * Create a new chat session
     */
    public function createSession(Request $request)
    {
        $request->validate([
            'platform' => 'nullable|string|max:255',
            'browser' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:255',
        ]);

        $user = Auth::user();
        
        // Check if the user has an active subscription
        // Free users (without active subscriptions) are restricted to email/ticket support only
        if (!$user->hasActiveSubscription()) {
            return response()->json([
                'success' => false,
                'message' => 'Live chat support is only available for paid plans. Free users can access support via email or ticket system.'
            ], 403);
        }
        
        $session = ChatSession::create([
            'user_id' => $user->id,
            'status' => 'open',
            'platform' => $request->platform,
            'browser' => $request->browser,
            'source' => $request->source,
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Chat session created successfully',
            'session_id' => $session->id
        ]);
    }

    /**
     * Add a message to a chat session
     */
    public function addMessage(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:chat_sessions,id',
            'sender' => 'required|string|in:user,assistant',
            'text' => 'required|string',
            'metadata' => 'nullable|array',
        ]);

        $user = Auth::user();
        
        // Check if the session belongs to the user
        $session = ChatSession::where('id', $request->session_id)
            ->where('user_id', $user->id)
            ->first();
            
        if (!$session) {
            return response()->json(['error' => 'Chat session not found'], 404);
        }
        
        // Update the last_message_at timestamp
        $session->update([
            'last_message_at' => now()
        ]);
        
        $message = ChatMessage::create([
            'chat_session_id' => $request->session_id,
            'user_id' => $user->id,
            'sender' => $request->sender,
            'status' => 'sent',
            'text' => $request->text,
            'metadata' => $request->metadata,
            'timestamp' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message added successfully',
            'message_id' => $message->id
        ]);
    }

    /**
     * Get all messages for a specific chat session
     */
    public function getSessionMessages($sessionId)
    {
        $user = Auth::user();
        
        // Check if the session belongs to the user
        $session = ChatSession::where('id', $sessionId)
            ->where('user_id', $user->id)
            ->first();
            
        if (!$session) {
            return response()->json(['error' => 'Chat session not found'], 404);
        }
        
        $messages = ChatMessage::where('chat_session_id', $sessionId)
            ->orderBy('created_at', 'asc')
            ->get();
            
        return response()->json($messages);
    }

    /**
     * Get all chat sessions for the authenticated user
     */
    public function getSessions()
    {
        $user = Auth::user();
        
        $sessions = ChatSession::where('user_id', $user->id)
            ->orderBy('last_message_at', 'desc')
            ->with(['messages' => function($query) {
                $query->orderBy('created_at', 'desc')->limit(1);
            }])
            ->get();
            
        return response()->json($sessions);
    }

    /**
     * Close a chat session
     */
    public function closeSession($sessionId)
    {
        $user = Auth::user();
        
        $session = ChatSession::where('id', $sessionId)
            ->where('user_id', $user->id)
            ->first();
            
        if (!$session) {
            return response()->json(['error' => 'Chat session not found'], 404);
        }
        
        $session->update([
            'status' => 'closed',
            'ended_at' => now()
        ]);
        
        return response()->json([
            'success' => true,
            'message' => 'Chat session closed successfully'
        ]);
    }

    /**
     * Delete a chat session and all its messages
     */
    public function deleteSession($sessionId)
    {
        $user = Auth::user();
        
        $session = ChatSession::where('id', $sessionId)
            ->where('user_id', $user->id)
            ->first();
            
        if (!$session) {
            return response()->json(['error' => 'Chat session not found'], 404);
        }
        
        // Delete all messages first
        ChatMessage::where('chat_session_id', $sessionId)->delete();
        
        // Then delete the session
        $session->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Chat session deleted successfully'
        ]);
    }
}

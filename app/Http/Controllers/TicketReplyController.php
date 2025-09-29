<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\TicketReply;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TicketReplyController extends Controller
{
    /**
     * Store a new reply for a ticket.
     */
    public function store(Request $request, SupportTicket $ticket)
    {
        $user = Auth::user();
        
        // Check if the user has permission to reply to this ticket
        if (!$user->isAdmin() && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $validated = $request->validate([
            'message' => 'required|string',
            'is_internal_note' => 'sometimes|boolean',
        ]);
        
        // Only admins can create internal notes
        if (isset($validated['is_internal_note']) && $validated['is_internal_note'] && !$user->isAdmin()) {
            return response()->json(['message' => 'Only admins can create internal notes'], 403);
        }
        
        $reply = new TicketReply();
        $reply->ticket_id = $ticket->id;
        $reply->user_id = $user->id;
        $reply->message = $validated['message'];
        $reply->is_internal_note = $user->isAdmin() && isset($validated['is_internal_note']) ? $validated['is_internal_note'] : false;
        $reply->save();
        
        // Update the ticket's status if needed
        if ($ticket->status === 'open' && $user->isAdmin()) {
            $ticket->status = 'in_progress';
            $ticket->save();
        }
        
        // Update the ticket's updated_at timestamp
        $ticket->touch();
        
        return response()->json([
            'message' => 'Reply added successfully',
            'reply' => $reply->load('user:id,name,email')
        ], 201);
    }
    
    /**
     * Get all replies for a ticket.
     */
    public function index(SupportTicket $ticket)
    {
        $user = Auth::user();
        
        // Check if the user has permission to view this ticket's replies
        if (!$user->isAdmin() && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $query = $ticket->replies()->with('user:id,name,email')->orderBy('created_at', 'asc');
        
        // Regular users can't see internal notes
        if (!$user->isAdmin()) {
            $query->where('is_internal_note', false);
        }
        
        return response()->json($query->get());
    }
}

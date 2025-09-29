<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SupportTicketController extends Controller
{
    /**
     * Display a listing of the tickets.
     * For regular users: only their tickets
     * For admins: all tickets or filtered by status
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = SupportTicket::with(['user:id,name,email', 'assignedAdmin:id,name,email'])
            ->withCount('replies');
            
        // If user is not an admin, only show their tickets
        if (!$user->isAdmin()) {
            $query->where('user_id', $user->id);
        } else {
            // Admin can filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            // Admin can filter by priority
            if ($request->has('priority')) {
                $query->where('priority', $request->priority);
            }
            
            // Admin can filter by assigned/unassigned
            if ($request->has('assigned')) {
                if ($request->assigned === 'true') {
                    $query->whereNotNull('assigned_to');
                } else {
                    $query->whereNull('assigned_to');
                }
            }
            
            // Admin can filter tickets assigned to them
            if ($request->has('assigned_to_me') && $request->assigned_to_me === 'true') {
                $query->where('assigned_to', $user->id);
            }
        }
        
        // Sorting
        $sortField = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $allowedSortFields = ['created_at', 'updated_at', 'status', 'priority'];
        
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('created_at', 'desc');
        }
        
        $perPage = $request->input('per_page', 15);
        return $query->paginate($perPage);
    }

    /**
     * Store a newly created ticket.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'required|string',
            'priority' => 'sometimes|string|in:low,medium,high,urgent',
        ]);

        $user = Auth::user();
        
        // Check if user has support access (based on free tier restriction)
        $userSubscription = $user->subscription;
        if ($userSubscription && $userSubscription->plan && isset($userSubscription->plan->support_available) && !$userSubscription->plan->support_available) {
            return response()->json([
                'message' => 'Your current plan does not include support access',
                'success' => false
            ], 403);
        }
        
        // Auto-determine priority based on plan if not specified
        $priority = $request->input('priority');
        
        if (!$priority && $userSubscription && $userSubscription->plan) {
            // Debugging: Log subscription details
            \Illuminate\Support\Facades\Log::info('User ID: ' . $user->id);
            \Illuminate\Support\Facades\Log::info('Subscription ID: ' . $userSubscription->id);
            \Illuminate\Support\Facades\Log::info('Plan ID from subscription: ' . $userSubscription->plan_id);
            
            // Force refresh the plan from database to get latest data
            $planModel = \App\Models\Plan::find($userSubscription->plan_id);
            if ($planModel) {
                $planName = strtolower($planModel->name);
                \Illuminate\Support\Facades\Log::info('Plan name from database: ' . $planName);
            } else {
                $planName = strtolower($userSubscription->plan->name);
                \Illuminate\Support\Facades\Log::info('Plan name from relation: ' . $planName);
            }
            
            // Set priority based on plan tier type, with special case for basic annual plans
            // Order matters here - check from most specific to least specific
            \Illuminate\Support\Facades\Log::info('Checking plan name: ' . $planName);
            
            if (strpos($planName, 'free') !== false) {
                $priority = 'low';
                \Illuminate\Support\Facades\Log::info('Priority set to low (free)');
            } elseif (strpos($planName, 'enterprise') !== false) {
                // Enterprise plans get urgent priority regardless of billing cycle
                $priority = 'urgent';
                \Illuminate\Support\Facades\Log::info('Priority set to urgent (enterprise)');
            } elseif (strpos($planName, 'pro') !== false) {
                // Pro plans get high priority regardless of billing cycle or one-time status
                $priority = 'high';
                \Illuminate\Support\Facades\Log::info('Priority set to high (pro)');
            } elseif (strpos($planName, 'basic annual') !== false) {
                // Basic Annual plans get medium priority
                $priority = 'medium';
                \Illuminate\Support\Facades\Log::info('Priority set to medium (basic annual)');
            } elseif (strpos($planName, 'basic') !== false) {
                // Regular Basic plans (non-annual) get low priority
                $priority = 'low';
                \Illuminate\Support\Facades\Log::info('Priority set to low (basic)');
            } elseif (strpos($planName, 'premium') !== false) {
                // Premium plans get urgent priority
                $priority = 'urgent';
                \Illuminate\Support\Facades\Log::info('Priority set to urgent (premium)');
            } else {
                // Default fallback
                $priority = 'medium';
                \Illuminate\Support\Facades\Log::info('Priority set to medium (default)');
            }
        } else {
            // Fallback if no subscription or priority was specified
            $priority = $priority ?? 'medium';
        }

        $ticket = new SupportTicket();
        $ticket->user_id = $user->id;
        $ticket->subject = $validated['subject'];
        $ticket->description = $validated['description'];
        $ticket->priority = $priority;
        $ticket->status = 'open';
        $ticket->save();

        return response()->json([
            'message' => 'Support ticket created successfully',
            'ticket' => $ticket->load('user:id,name,email')
        ], 201);
    }

    /**
     * Display the specified ticket.
     */
    public function show(SupportTicket $ticket)
    {
        $user = Auth::user();
        
        // Check if the user has permission to view this ticket
        if (!$user->isAdmin() && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $ticket->load([
            'user:id,name,email', 
            'assignedAdmin:id,name,email',
            'replies' => function($query) use ($user) {
                $query->with('user:id,name,email')
                      ->orderBy('created_at', 'asc');
                
                // Only show internal notes to admins
                if (!$user->isAdmin()) {
                    $query->where('is_internal_note', false);
                }
            }
        ]);
        
        return response()->json($ticket);
    }

    /**
     * Update the specified ticket.
     * Regular users can only update priority and add replies
     * Admins can update status, priority, and assign tickets
     */
    public function update(Request $request, SupportTicket $ticket)
    {
        $user = Auth::user();
        
        // Regular users can only update their own tickets
        if (!$user->isAdmin() && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $validated = $request->validate([
            'status' => 'sometimes|string|in:open,in_progress,resolved,closed',
            'priority' => 'sometimes|string|in:low,medium,high,urgent',
            'assigned_to' => 'sometimes|nullable|exists:users,id',
        ]);
        
        // Regular users can only update priority
        if (!$user->isAdmin()) {
            if ($request->has('status') || $request->has('assigned_to')) {
                return response()->json(['message' => 'You can only update the priority'], 403);
            }
            
            if ($request->has('priority')) {
                $ticket->priority = $validated['priority'];
            }
        } else {
            // Admins can update status, priority, and assign tickets
            if ($request->has('status')) {
                $oldStatus = $ticket->status;
                $ticket->status = $validated['status'];
                
                // If status is changed to resolved, set resolved_at timestamp
                if ($validated['status'] === 'resolved' && $oldStatus !== 'resolved') {
                    $ticket->resolved_at = now();
                }
                
                // If status is changed from resolved, clear resolved_at timestamp
                if ($validated['status'] !== 'resolved' && $oldStatus === 'resolved') {
                    $ticket->resolved_at = null;
                }
            }
            
            if ($request->has('priority')) {
                $ticket->priority = $validated['priority'];
            }
            
            if ($request->has('assigned_to')) {
                // Check if the assigned user is an admin
                if ($validated['assigned_to']) {
                    $assignedUser = User::find($validated['assigned_to']);
                    if (!$assignedUser || !$assignedUser->isAdmin()) {
                        return response()->json(['message' => 'Can only assign tickets to admin users'], 400);
                    }
                }
                
                $ticket->assigned_to = $validated['assigned_to'];
            }
        }
        
        $ticket->save();
        
        return response()->json([
            'message' => 'Ticket updated successfully',
            'ticket' => $ticket->load(['user:id,name,email', 'assignedAdmin:id,name,email'])
        ]);
    }

    /**
     * Get ticket statistics for admins
     */
    public function statistics()
    {
        $user = Auth::user();
        
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }
        
        $stats = [
            'total' => SupportTicket::count(),
            'open' => SupportTicket::where('status', 'open')->count(),
            'in_progress' => SupportTicket::where('status', 'in_progress')->count(),
            'resolved' => SupportTicket::where('status', 'resolved')->count(),
            'closed' => SupportTicket::where('status', 'closed')->count(),
            'unassigned' => SupportTicket::whereNull('assigned_to')->count(),
            'assigned_to_me' => SupportTicket::where('assigned_to', $user->id)->count(),
            'by_priority' => [
                'low' => SupportTicket::where('priority', 'low')->count(),
                'medium' => SupportTicket::where('priority', 'medium')->count(),
                'high' => SupportTicket::where('priority', 'high')->count(),
                'urgent' => SupportTicket::where('priority', 'urgent')->count(),
            ],
            'recent_activity' => SupportTicket::with(['user:id,name,email'])
                ->orderBy('updated_at', 'desc')
                ->limit(5)
                ->get()
        ];
        
        return response()->json($stats);
    }
    
    /**
     * Get tickets assigned to the current admin user
     */
    public function assignedToMe(Request $request)
    {
        $user = Auth::user();
        
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Only admin users can access this endpoint'], 403);
        }
        
        $query = SupportTicket::with(['user:id,name,email', 'assignedAdmin:id,name,email'])
            ->withCount('replies')
            ->where('assigned_to', $user->id);
        
        // Admin can filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }
        
        // Admin can filter by priority
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }
        
        // Sorting
        $sortField = $request->input('sort_by', 'created_at');
        $sortDirection = $request->input('sort_direction', 'desc');
        $allowedSortFields = ['created_at', 'updated_at', 'status', 'priority'];
        
        if (in_array($sortField, $allowedSortFields)) {
            $query->orderBy($sortField, $sortDirection === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('created_at', 'desc');
        }
        
        $perPage = $request->input('per_page', 15);
        return $query->paginate($perPage);
    }

    /**
     * Assign a ticket to an admin (super_admin only)
     */
    public function assign(Request $request, SupportTicket $ticket)
    {
        $user = Auth::user();
        
        if (!$user->isSuperAdmin()) {
            return response()->json(['message' => 'Only super admins can assign tickets'], 403);
        }
        
        $validated = $request->validate([
            'admin_id' => 'required|exists:users,id',
        ]);
        
        // Check if the assigned user is an admin
        $assignedUser = User::find($validated['admin_id']);
        if (!$assignedUser || !$assignedUser->isAdmin()) {
            return response()->json(['message' => 'Can only assign tickets to admin users'], 400);
        }
        
        $ticket->assigned_to = $validated['admin_id'];
        $ticket->save();
        
        return response()->json([
            'message' => 'Ticket assigned successfully',
            'ticket' => $ticket->load(['user:id,name,email', 'assignedAdmin:id,name,email'])
        ]);
    }
}

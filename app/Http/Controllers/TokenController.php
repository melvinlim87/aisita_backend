<?php

namespace App\Http\Controllers;

use App\Models\ManualTokenAddition;
use App\Models\Purchase;
use App\Models\Referral;
use App\Models\User;
use App\Models\TokenUsage;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;
use Illuminate\Http\JsonResponse;

class TokenController extends Controller
{
    // Admin role IDs for permission checks
    const ADMIN_ROLES = [2, 3]; // 2 = admin, 3 = super_admin
        /**
     * The token service instance   .
     *
     * @var \App\Services\TokenService
     */
    protected $tokenService;

    /**
     * Token packages with a dual markup strategy
     * 1. API usage already has 10x markup (implemented in OpenAIController)
     * 2. These packages have additional markup on purchase price
     */
    protected $tokenPackages = [
        'starter' => [
            'name' => 'Starter Package',
            'tokens' => 35000,
            'price' => 10.00,
            'original_value' => 7.50, // Based on our API markup rates
            'savings' => '5%', // Small savings for starter package
            //'price_id' => 'price_1S2t2a2MDBrgqcCAU4sVO24u', SANDBOX
            'price_id' => 'price_1SAoZxRqFaHWPtRlxbZUZAuq',
            'description' => 'Great for casual users and basic analysis'
        ],
        'standard' => [
            'name' => 'Standard Package',
            'tokens' => 175000,
            'price' => 50.00,
            'original_value' => 45.00, // Based on our API markup rates
            'savings' => '10%', // Better savings for standard package
            //'price_id' => 'price_1S2t3F2MDBrgqcCAbsjEndmI', SANDBOX
            'price_id' => 'price_1SAoZvRqFaHWPtRl5rDJs0Uu',
            'description' => 'Our most popular package for regular users'
        ],
        'premium' => [
            'name' => 'Premium Package',
            'tokens' => 350000,
            'price' => 100.00, 
            'original_value' => 90.00, // Based on our API markup rates
            'savings' => '20%', // Best savings for largest package
            //'price_id' => 'price_1S2t3z2MDBrgqcCAXO9WFLf9', SANDBOX
            'price_id' => 'price_1SAoZsRqFaHWPtRlgWZbHp6T',
            'description' => 'Best value for power users and teams'
        ]
    ];

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\TokenService  $tokenService
     * @return void
     */
    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
    }

    /**
     * List available token packages.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function listPackages(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'packages' => $this->tokenPackages
        ]);
    }

    /**
     * Create checkout session for token purchase.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function purchaseTokens(Request $request): JsonResponse
    {
        // Validate request
        $validated = $request->validate([
            'package_id' => 'required|string|in:micro,starter,standard,premium,enterprise',
        ]);
        
        // Get package details
        $packageId = $validated['package_id'];
        if (!isset($this->tokenPackages[$packageId])) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid package selected'
            ], 400);
        }
        
        $package = $this->tokenPackages[$packageId];
        $user = Auth::user();
        
        // Check if this is a user's first token purchase after consuming free tokens
        // Only allow token purchases if the user has an active subscription
        if (!$user->hasActiveSubscription()) {
            return response()->json([
                'success' => false,
                'error' => 'Free users must subscribe to a paid plan before purchasing tokens.'
            ], 403);
        }
        
        try {
            // Create Stripe checkout session using the pre-defined price ID
            $session = \Stripe\Checkout\Session::create([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price' => $package['price_id'], // Use the pre-defined price ID from Stripe
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => env('FRONTEND_URL') . '/credit-history?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => env('FRONTEND_URL') . '/membership-plan',
                'metadata' => [
                    'user_id' => $user->id,
                    'package_id' => $packageId,
                    'token_amount' => $package['tokens'],
                    'price' => $package['price'],
                    'action' => 'token_purchase'
                ]
            ]);
            
            return response()->json([
                'success' => true,
                'session_id' => $session->id,
                'checkout_url' => $session->url
            ]);
            
        } catch (ApiErrorException $e) {
            Log::error('Stripe API Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Payment processing error: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's token balances.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBalance(Request $request): JsonResponse
    {
        $user = $request->user();
        $tokenBalances = $this->tokenService->getUserTokens($user->id);
        
        return response()->json([
            'success' => true,
            'subscription_token' => $tokenBalances['subscription_token'],
            'addons_token' => $tokenBalances['addons_token'],
            'free_token' => $tokenBalances['free_token'],
            'registration_token' => $tokenBalances['registration_token']
        ]);
    }

    /**
     * Get user's token usage history.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUsageHistory(Request $request): JsonResponse
    {
        $user = Auth::user();
        
        // Validate request parameters
        $validated = $request->validate([
            'limit' => 'integer|min:1|max:100',
            'page' => 'integer|min:1',
            'type' => 'nullable|string|in:purchase,usage,all'
        ]);
        
        $limit = $validated['limit'] ?? 10;
        $page = $validated['page'] ?? 1;
        $type = $validated['type'] ?? 'all';
        
        try {
            // Get referral
            // Get purchases from database
            $query = \App\Models\Purchase::where('user_id', $user->id);
            
            // Filter by type if specified
            if ($type !== 'all') {
                $query->where('type', $type);
            }

            // Also referral awarded tokens
            $query->orWhere('referrer_id', $user->id);
            
            // Order by creation date
            $query->orderBy('created_at', 'desc');
            
            // Paginate results
            $usageHistory = $query->paginate($limit, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'data' => $usageHistory
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting usage history: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to get usage history: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get token usage data with filtering options.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTokenUsageData(Request $request): JsonResponse
    {
        // Get the authenticated user
        $user = Auth::user();
        
        // Debug user authentication
        Log::info('Token usage data request', [
            'user_id' => $user->id,
            'user_role' => $user->role_id,
            'request_params' => $request->all()
        ]);
        
        // Check if the user has admin permissions (admin or super_admin)
        $isAdmin = in_array($user->role_id, self::ADMIN_ROLES);
        
        Log::info('Admin status check', [
            'is_admin' => $isAdmin,
            'user_role' => $user->role_id,
            'allowed_admin_roles' => self::ADMIN_ROLES
        ]);
        
        // Build the query
        $query = TokenUsage::query();
        
        // Admin users can see all data unless they specifically filter by user_id
        if ($isAdmin) {
            if ($request->has('user_id')) {
                $query->where('user_id', $request->input('user_id'));
                Log::info('Admin filtering by specific user_id', ['filtered_user_id' => $request->input('user_id')]);
            }
            // For admins with no user_id filter, show all records (no where clause needed)
        } else {
            // Non-admins can only see their own data
            $query->where('user_id', $user->id);
            Log::info('Regular user restricted to own records', ['user_id' => $user->id]);
        }
        
        // Optional filters
        if ($request->has('feature')) {
            $query->where('feature', $request->input('feature'));
        }
        
        if ($request->has('model')) {
            $query->where('model', $request->input('model'));
        }
        
        if ($request->has('analysis_type')) {
            $query->where('analysis_type', $request->input('analysis_type'));
        }
        
        // Date range filter
        if ($request->has('from_date')) {
            $query->whereDate('timestamp', '>=', $request->input('from_date'));
        }
        
        if ($request->has('to_date')) {
            $query->whereDate('timestamp', '<=', $request->input('to_date'));
        }

        if ($request->has('end_date')) {
            $query->whereDate('timestamp', '>=', $request->input('end_date'));
        }
        
        if ($request->has('start_date')) {
            $query->whereDate('timestamp', '<=', $request->input('start_date'));
        }
        
        // Get total records for pagination
        $total = $query->count();
        
        // Apply sorting
        $sortField = $request->input('sort_by', 'timestamp');
        $sortDirection = $request->input('sort_direction', 'desc');
        $query->orderBy($sortField, $sortDirection);
        
        // Apply pagination
        $perPage = $request->input('per_page', 15);
        $page = $request->input('page', 1);
        $records = $query->skip(($page - 1) * $perPage)->take($perPage)->get();
        
        // For admins, include user info with each record
        if ($isAdmin) {
            $records->load('user:id,name,email');
        }
        
        // Calculate usage statistics
        $stats = [
            'total_input_tokens' => $query->sum('input_tokens'),
            'total_output_tokens' => $query->sum('output_tokens'),
            'total_tokens_used' => $query->sum('tokens_used'),
            'average_input_tokens' => $query->avg('input_tokens'),
            'average_output_tokens' => $query->avg('output_tokens'),
            'total_records' => $total
        ];
        
        return response()->json([
            'success' => true,
            'data' => $records,
            'stats' => $stats,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => ceil($total / $perPage)
            ]
        ]);
    }
    
    /**
     * Verify a token purchase checkout session.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyPurchase(Request $request): JsonResponse
    {
        // Validate the session ID
        $validated = $request->validate([
            'session_id' => 'required|string',
        ]);

        $sessionId = $validated['session_id'];
        
        try {
            $stripe = new \Stripe\StripeClient(env('STRIPE_SECRET_KEY'));
            
            // Retrieve the checkout session
            $session = $stripe->checkout->sessions->retrieve($sessionId);
            
            // Get metadata from session
            $metadata = $session->metadata->toArray();
            
            // Validate this is a token purchase
            if (!isset($metadata['action']) || $metadata['action'] !== 'token_purchase') {
                return response()->json([
                    'success' => false,
                    'message' => 'Not a token purchase session',
                    'metadata' => $metadata
                ], 400);
            }
            
            // Check if user ID is present
            if (!isset($metadata['user_id']) || !isset($metadata['token_amount'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing user ID or token amount in session metadata',
                    'metadata' => $metadata
                ], 400);
            }
            
            // Check payment status
            if ($session->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not completed',
                    'status' => $session->payment_status
                ], 400);
            }
            
            // Get the user
            $user = \App\Models\User::find($metadata['user_id']);
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found',
                ], 404);
            }
            
            // Get package details
            $packageId = $metadata['package_id'];
            $package = $this->tokenPackages[$packageId] ?? null;
            
            if (!$package) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid package ID',
                ], 400);
            }
            
            // Check if purchase was already processed via webhook
            // Look for a purchase record with this session ID
            $existingPurchase = \App\Models\Purchase::where('session_id', $sessionId)->first();
            
            if ($existingPurchase) {
                // Purchase already processed, return success with token balances
                $currentTokens = $this->tokenService->getUserTokens($user->id);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Token purchase already processed',
                    'subscription_token' => $currentTokens['subscription_token'],
                    'addons_token' => $currentTokens['addons_token'],
                    'purchase' => [
                        'id' => $existingPurchase->id,
                        'amount' => $existingPurchase->amount,
                        'token_amount' => $existingPurchase->token_amount,
                        'created_at' => $existingPurchase->created_at
                    ]
                ]);
            }
            
            // Add tokens to user account
            $tokenAmount = intval($metadata['token_amount']);
            $price = floatval($metadata['price']);
            
            // Create a proper purchase data array for TokenService
            $purchaseData = [
                'sessionId' => $sessionId,
                'priceId' => $package['price_id'],
                'amount' => $price,
                'customerEmail' => $user->email,
                'currency' => 'usd',
                'status' => 'completed',
                'type' => 'purchase'
            ];

            // If we get here, the purchase is valid but hasn't been processed yet
            // This can happen if the webhook hasn't fired yet
            // For regular token purchases via /tokens/verify-purchase, always use addons_token
            // unless explicitly specified otherwise
            $tokenType = 'addons_token'; // Default to addons_token for direct token purchases
            
            // Only override if explicitly specified in metadata
            if (isset($metadata['token_type'])) {
                $tokenType = $metadata['token_type'];
            }
            
            Log::info("Token purchase verification - Adding tokens to {$tokenType}", [
                'user_id' => $user->id, 
                'tokens' => $tokenAmount, 
                'token_type' => $tokenType,
                'package_id' => $packageId
            ]);
            
            // Update user tokens with purchase data and specified token type
            $this->tokenService->updateUserTokens(
                $user->id, 
                $tokenAmount, 
                $purchaseData,
                $tokenType
            );
            
            // Get updated token balances
            $currentTokens = $this->tokenService->getUserTokens($user->id);
            
            return response()->json([
                'success' => true,
                'message' => 'Token purchase verified and tokens added',
                'subscription_token' => $currentTokens['subscription_token'],
                'addons_token' => $currentTokens['addons_token'],
                'added' => $tokenAmount,
                'package' => $packageId
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error verifying token purchase: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error verifying checkout: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Manually add tokens to a user (admin only)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function manuallyAddTokens(Request $request): JsonResponse
    {
        // Validate request
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'token_amount' => 'required|integer|min:1',
            'token_type' => 'required|string|in:subscription_token,addons_token',
            'reason' => 'required|string|max:255',
        ]);
        
        // Check if user has admin privileges
        $user = Auth::user();
        if (!in_array($user->role_id, self::ADMIN_ROLES)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to add tokens manually.'
            ], 403);
        }
        
        // Get target user
        $targetUser = User::find($validated['user_id']);
        
        // Create a record for audit purposes
        $auditData = [
            'sessionId' => 'manual-' . now()->timestamp,
            'priceId' => 'manual-token-' . now()->timestamp, // Add a dummy price_id to satisfy DB constraint
            'amount' => 0, // No money involved
            'customerEmail' => $targetUser->email,
            'currency' => 'usd',
            'status' => 'completed',
            'type' => 'manual',
            'description' => $validated['reason'] . ' (Added by: ' . $user->name . ')',
        ];
        
        // Log this action
        Log::info("Manual token addition", [
            'admin_id' => $user->id,
            'admin_name' => $user->name,
            'target_user_id' => $validated['user_id'],
            'token_amount' => $validated['token_amount'],
            'token_type' => $validated['token_type'],
            'reason' => $validated['reason']
        ]);
        
        // Add tokens to user account
        $result = $this->tokenService->updateUserTokens(
            $validated['user_id'],
            $validated['token_amount'],
            $auditData,
            $validated['token_type']
        );
        
        if ($result) {
            // Find the purchase record that was created
            $purchaseId = Purchase::where('session_id', $auditData['sessionId'])->value('id');
            
            // Record this manual token addition in our new table
            ManualTokenAddition::create([
                'user_id' => $validated['user_id'],
                'admin_id' => $user->id,
                'admin_name' => $user->name,
                'token_amount' => $validated['token_amount'],
                'token_type' => $validated['token_type'],
                'reason' => $validated['reason'],
                'purchase_id' => $purchaseId
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add tokens to user account'
            ], 500);
        }
        
        // Get updated token balances
        $currentTokens = $this->tokenService->getUserTokens($validated['user_id']);
        
        return response()->json([
            'success' => true,
            'message' => 'Tokens added successfully',
            'user_id' => $validated['user_id'],
            'subscription_token' => $currentTokens['subscription_token'],
            'addons_token' => $currentTokens['addons_token'],
            'added' => $validated['token_amount'],
            'token_type' => $validated['token_type']
        ]);
    }

    /**
     * Get manual token additions with filtering and pagination
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getManualTokenAdditions(Request $request): JsonResponse
    {
        // Check if user has admin privileges
        $user = Auth::user();
        if (!in_array($user->role_id, self::ADMIN_ROLES)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to view manual token additions.'
            ], 403);
        }

        // Validate request parameters
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',
            'admin_id' => 'nullable|exists:users,id',
            'token_type' => 'nullable|in:subscription_token,addons_token',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',
        ]);

        // Build query
        $query = ManualTokenAddition::with(['user:id,name,email', 'admin:id,name,email']);

        // Apply filters
        if (isset($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (isset($validated['admin_id'])) {
            $query->where('admin_id', $validated['admin_id']);
        }

        if (isset($validated['token_type'])) {
            $query->where('token_type', $validated['token_type']);
        }

        if (isset($validated['from_date'])) {
            $query->whereDate('created_at', '>=', $validated['from_date']);
        }

        if (isset($validated['to_date'])) {
            $query->whereDate('created_at', '<=', $validated['to_date']);
        }

        // Order by newest first
        $query->orderBy('created_at', 'desc');

        // Paginate results
        $perPage = $validated['per_page'] ?? 15;
        $page = $validated['page'] ?? 1;
        $results = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $results,
            'message' => 'Manual token additions retrieved successfully'
        ]);
    }
}

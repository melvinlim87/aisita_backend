<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\TokenService;
use App\Services\SubscriptionService;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Exception\ApiErrorException;

class StripeController extends Controller
{
    // Map price IDs to token amounts
    private const TOKEN_AMOUNTS = [
        // New token packages
        '7000_tokens' => 7000,
        '40000_tokens' => 40000,
        '100000_tokens' => 100000,
        // Specific price IDs
        'price_1R4cZ22NO6PNHfEnEhmEzX2y' => 7000,   // 7,000 tokens
        'price_1R4cZj2NO6PNHfEn4XiPU4tI' => 40000,  // 40,000 tokens
        'price_1R4caA2NO6PNHfEncTFmFBd4' => 100000  // 100,000 tokens
    ];

    protected $tokenService;
    protected $subscriptionService;

    public function __construct(TokenService $tokenService, SubscriptionService $subscriptionService)
    {
        // Initialize Stripe with the secret key
        Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
        $this->tokenService = $tokenService;
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * Create a Stripe checkout session
     */
    public function createCheckoutSession(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'priceId' => 'required|string',
                'userId' => 'required|string',
                'customerInfo.name' => 'nullable|string',
                'customerInfo.email' => 'nullable|email',
            ]);

            $priceId = $validated['priceId'];
            $userId = $validated['userId'];
            $customerInfo = $validated['customerInfo'] ?? [];

            // Debug Stripe configuration
            Log::info('Stripe API Key (partial): ' . substr(env('STRIPE_SECRET_KEY', 'not-set'), 0, 8) . '...');
            Log::info('Attempting to verify price ID: ' . $priceId);
            
            // Verify price ID exists in Stripe
            try {
                // Set API key explicitly to ensure it's using the correct one
                \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
                
                $price = \Stripe\Price::retrieve($priceId);
                Log::info('Price retrieved successfully: ' . $price->id);
                
                if (!$price->active) {
                    Log::warning('Price exists but is not active: ' . $price->id);
                    return response()->json([
                        'success' => false,
                        'message' => 'Price is not active'
                    ], 400);
                }
            } catch (ApiErrorException $e) {
                Log::error('Error retrieving price from Stripe: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or inactive price ID',
                    'error' => $e->getMessage()
                ], 400);
            }

            // Create or retrieve customer if email is provided
            $customerId = null;
            if (isset($customerInfo['email'])) {
                try {
                    // Search for existing customers with this email
                    $customers = \Stripe\Customer::all([
                        'email' => $customerInfo['email'],
                        'limit' => 1
                    ]);
                    
                    if (count($customers->data) > 0) {
                        // Update existing customer
                        $customerId = $customers->data[0]->id;
                        \Stripe\Customer::update($customerId, [
                            'name' => $customerInfo['name'] ?? null
                        ]);
                    } else {
                        // Create new customer
                        $customer = \Stripe\Customer::create([
                            'email' => $customerInfo['email'],
                            'name' => $customerInfo['name'] ?? null,
                            'metadata' => [
                                'userId' => $userId
                            ]
                        ]);
                        $customerId = $customer->id;
                    }
                } catch (ApiErrorException $e) {
                    Log::error('Error creating/updating customer: ' . $e->getMessage());
                    // Continue without customer ID if there's an error
                }
            }

            // Create checkout session with appropriate customer parameters
            $sessionConfig = [
                'mode' => $mode, // Use the provided mode parameter
                'payment_method_types' => ['card'],
                'line_items' => [
                    [
                        'price' => $priceId,
                        'quantity' => 1,
                    ],
                ],
                'success_url' => env('FRONTEND_URL') . '/profile?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => env('FRONTEND_URL') . '/profile',
                'metadata' => array_merge([
                    'userId' => $userId,
                    'customer_name' => $customerInfo['name'] ?? '',
                ], $metadata),
                'payment_intent_data' => [
                    'metadata' => [
                        'userId' => $userId,
                        'price_id' => $priceId,
                        'session_id' => '{CHECKOUT_SESSION_ID}',
                        'customer_name' => $customerInfo['name'] ?? '',
                        'customer_email' => $customerInfo['email'] ?? '',
                    ]
                ]
            ];
            
            // Add customer-related parameters based on what we have
            if ($customerId) {
                // If we have a customer ID, use it
                $sessionConfig['customer'] = $customerId;
            } else if (isset($customerInfo['email'])) {
                // If we have an email but no customer ID, let Stripe create a customer
                $sessionConfig['customer_email'] = $customerInfo['email'];
                $sessionConfig['customer_creation'] = 'always';
            }
            
            $session = Session::create($sessionConfig);

            return response()->json([
                'success' => true,
                'id' => $session->id,
                'sessionUrl' => $session->url
            ]);
        } catch (ApiErrorException $e) {
            Log::error('Stripe error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error creating checkout session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session'
            ], 500);
        }
    }

    /**
     * Verify a Stripe checkout session
     */
    public function verifySession(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'session_id' => 'required|string',
            ]);

            $sessionId = $validated['session_id'];

            // Retrieve the session
            $session = Session::retrieve($sessionId);
            
            // Check if payment was successful
            if ($session->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'error' => 'Payment not completed',
                    'status' => $session->payment_status
                ], 400);
            }

            $userId = $session->metadata->userId ?? null;
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'error' => 'No user ID found in session metadata'
                ], 400);
            }

            // Get line items to determine token amount
            $lineItems = \Stripe\Checkout\Session::allLineItems($sessionId, ['limit' => 1]);
            
            if (count($lineItems->data) === 0) {
                return response()->json([
                    'success' => false,
                    'error' => 'No line items found for this session'
                ], 400);
            }

            // Get the price ID from the line item
            $priceId = $lineItems->data[0]->price->id;
            
            // Determine which token package was purchased
            // This would be replaced with your actual mapping logic
            $packageKey = null;
            foreach (self::TOKEN_AMOUNTS as $key => $amount) {
                if (strpos($priceId, $key) !== false) {
                    $packageKey = $key;
                    break;
                }
            }
            
            $tokensToAdd = self::TOKEN_AMOUNTS[$packageKey] ?? 0;
            
            if ($tokensToAdd === 0) {
                // Fallback: Try to extract token amount from price description or metadata
                // This is a simplified example - you would need to adapt this to your actual data structure
                $tokensToAdd = $lineItems->data[0]->price->metadata->tokens ?? 0;
            }
            
            if ($tokensToAdd === 0) {
                return response()->json([
                    'success' => false,
                    'error' => 'Could not determine token amount for this purchase'
                ], 400);
            }
            
            // Check if purchase has already been processed
            $existingPurchase = \App\Models\Purchase::where('session_id', $sessionId)->first();
            
            if ($existingPurchase) {
                // Purchase already processed, return success with token balances
                $currentTokens = $this->tokenService->getUserTokens($userId);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Token purchase already processed',
                    'subscription_token' => $currentTokens['subscription_token'],
                    'addons_token' => $currentTokens['addons_token'],
                    'purchase' => [
                        'id' => $existingPurchase->id,
                        'amount' => $existingPurchase->amount,
                        'token_amount' => $existingPurchase->tokens,
                        'created_at' => $existingPurchase->created_at
                    ]
                ]);
            }
            
            // Update user tokens in database
            try {
                // Check user's current token balances
                $tokenBalances = $this->tokenService->getUserTokens($userId);
                $subscriptionTokenBefore = $tokenBalances['subscription_token'];
                $newTotal = $subscriptionTokenBefore + $tokensToAdd;
                
                $purchaseData = [
                    'sessionId' => $sessionId,
                    'priceId' => $priceId,
                    'amount' => ($lineItems->data[0]->amount_total ?? 0) / 100, // Convert to dollars
                    'status' => $session->payment_status,
                    'customerEmail' => $session->customer_details->email ?? null,
                    'currency' => $lineItems->data[0]->currency ?? 'usd'
                ];
                
                // Determine token type based on metadata or package type
                $tokenType = 'subscription_token'; // Default to subscription_token
                
                // Check if there's a token_type in metadata or if we can determine it from package name
                if (isset($session->metadata->token_type)) {
                    $tokenType = $session->metadata->token_type;
                } else if (isset($packageKey)) {
                    // Try to determine token type from package key
                    if (stripos($packageKey, 'addon') !== false || stripos($packageKey, 'add-on') !== false) {
                        $tokenType = 'addons_token';
                    }
                }
                
                Log::info("Adding tokens to {$tokenType}", ['user_id' => $userId, 'tokens' => $tokensToAdd, 'token_type' => $tokenType]);
                
                // Add tokens to user account
                $success = $this->tokenService->updateUserTokens($userId, $tokensToAdd, $purchaseData, $tokenType);
                
                // Get updated token balances
                $updatedTokenBalances = $this->tokenService->getUserTokens($userId);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Token purchase verified and tokens added',
                    'subscription_token' => $updatedTokenBalances['subscription_token'],
                    'addons_token' => $updatedTokenBalances['addons_token'],
                    'added' => $tokensToAdd,
                    'package' => $packageKey
                ]);
            } catch (\Exception $e) {
                Log::error('Error updating user tokens: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to update user tokens: ' . $e->getMessage()
                ], 500);
            }
        } catch (ApiErrorException $e) {
            Log::error('Stripe error verifying session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Verification error: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error verifying session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Verification error'
            ], 500);
        }
    }

    /**
     * Verify a Stripe checkout session via POST request
     */
    public function verifySessionPost(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'priceId' => 'required|string',
                'userId' => 'required|string',
                'customerInfo.name' => 'nullable|string',
                'customerInfo.email' => 'nullable|email',
            ]);

            $priceId = $validated['priceId'];
            $userId = $validated['userId'];
            $customerInfo = $validated['customerInfo'] ?? [];

            // Verify price ID exists in Stripe
            try {
                $price = \Stripe\Price::retrieve($priceId);
                
                if (!$price->active) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Price is not active'
                    ], 400);
                }
            } catch (ApiErrorException $e) {
                Log::error('Error retrieving price from Stripe: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Invalid or inactive price ID'
                ], 400);
            }

            // Direct lookup by price ID first
            $tokensToAdd = self::TOKEN_AMOUNTS[$priceId] ?? 0;
            Log::info('Direct price ID lookup result: ' . $tokensToAdd . ' for price ID: ' . $priceId);
            
            // If direct lookup fails, try partial matching
            if ($tokensToAdd === 0) {
                Log::info('Attempting partial matching for price ID: ' . $priceId);
                foreach (self::TOKEN_AMOUNTS as $key => $amount) {
                    Log::info('Checking if price ID ' . $priceId . ' contains ' . $key);
                    if (strpos($priceId, $key) !== false) {
                        $tokensToAdd = $amount;
                        Log::info('Found match: ' . $key . ' with amount: ' . $amount);
                        break;
                    }
                }
            }
            
            // If still no match, try to get token amount from price metadata
            if ($tokensToAdd === 0) {
                Log::info('Checking price metadata for tokens');
                if (isset($price->metadata) && isset($price->metadata->tokens)) {
                    $tokensToAdd = $price->metadata->tokens;
                    Log::info('Found tokens in metadata: ' . $tokensToAdd);
                } else {
                    Log::info('No tokens found in metadata');
                }
            }
            
            // Fallback: Assign a default value based on price amount
            if ($tokensToAdd === 0) {
                $priceAmount = ($price->unit_amount ?? 0) / 100;
                Log::info('Using fallback based on price amount: $' . $priceAmount);
                
                // Simple fallback logic - adjust as needed
                if ($priceAmount <= 10) {
                    $tokensToAdd = 7000;
                } else if ($priceAmount <= 50) {
                    $tokensToAdd = 40000;
                } else {
                    $tokensToAdd = 100000;
                }
                
                Log::info('Assigned fallback tokens: ' . $tokensToAdd);
            }
            
            if ($tokensToAdd === 0) {
                Log::error('Failed to determine token amount for price ID: ' . $priceId);
                return response()->json([
                    'success' => false,
                    'error' => 'Could not determine token amount for this price',
                    'priceId' => $priceId
                ], 400);
            }

            // Create or retrieve customer in Stripe
            $customerId = null;
            try {
                if (isset($customerInfo['email'])) {
                    // Search for existing customers with this email
                    $customers = \Stripe\Customer::all([
                        'email' => $customerInfo['email'],
                        'limit' => 1
                    ]);
                    
                    if (count($customers->data) > 0) {
                        // Use existing customer
                        $customerId = $customers->data[0]->id;
                        Log::info('Using existing customer: ' . $customerId);
                    } else {
                        // Create new customer
                        $customer = \Stripe\Customer::create([
                            'email' => $customerInfo['email'],
                            'name' => $customerInfo['name'] ?? null,
                            'metadata' => [
                                'userId' => $userId
                            ]
                        ]);
                        $customerId = $customer->id;
                        Log::info('Created new customer: ' . $customerId);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Error creating/finding customer: ' . $e->getMessage());
                // Continue without customer ID
            }
            
            // Create a checkout session instead of direct payment
            try {
                $sessionConfig = [
                    'mode' => 'payment',
                    'payment_method_types' => ['card'],
                    'line_items' => [
                        [
                            'price' => $priceId,
                            'quantity' => 1,
                        ],
                    ],
                    'success_url' => env('FRONTEND_URL', 'https://decyphers.com') . '/profile?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => env('FRONTEND_URL', 'https://decyphers.com') . '/profile',
                    'metadata' => [
                        'userId' => $userId,
                        'customer_name' => $customerInfo['name'] ?? '',
                        'direct_verification' => 'true'
                    ],
                    'payment_intent_data' => [
                        'metadata' => [
                            'userId' => $userId,
                            'price_id' => $priceId,
                            'tokens' => $tokensToAdd,
                            'session_id' => '{CHECKOUT_SESSION_ID}',
                            'customer_name' => $customerInfo['name'] ?? '',
                            'customer_email' => $customerInfo['email'] ?? '',
                        ]
                    ]
                ];
                
                // Add customer-related parameters based on what we have
                if ($customerId) {
                    // If we have a customer ID, use it
                    $sessionConfig['customer'] = $customerId;
                } else if (isset($customerInfo['email'])) {
                    // If we have an email but no customer ID, let Stripe create a customer
                    $sessionConfig['customer_email'] = $customerInfo['email'];
                    $sessionConfig['customer_creation'] = 'always';
                }
                
                $session = Session::create($sessionConfig);
                Log::info('Created checkout session: ' . $session->id);
                
                // Update tokens in database
                // In production, you might want to wait for the webhook or session verification
                $purchaseData = [
                    'sessionId' => $session->id,
                    'priceId' => $priceId,
                    'amount' => ($price->unit_amount ?? 0) / 100, // Convert to dollars
                    'status' => 'pending', // Status is pending until payment is completed
                    'customerEmail' => $customerInfo['email'] ?? null,
                    'currency' => $price->currency ?? 'usd',
                    'type' => 'purchase' // Explicitly set the transaction type
                ];
                
                // Determine token type based on metadata or package key
                $tokenType = 'subscription_token'; // Default to subscription_token
                
                // Check if there's a token_type in metadata
                if (isset($price->metadata) && isset($price->metadata->token_type)) {
                    $tokenType = $price->metadata->token_type;
                } else if (isset($priceId)) {
                    // Try to determine token type from price ID
                    if (stripos($priceId, 'addon') !== false || stripos($priceId, 'add-on') !== false) {
                        $tokenType = 'addons_token';
                    }
                }
                
                Log::info("Adding tokens to {$tokenType}", ['user_id' => $userId, 'tokens' => $tokensToAdd, 'token_type' => $tokenType]);
                
                // Update user tokens in database
                $success = $this->tokenService->updateUserTokens($userId, $tokensToAdd, $purchaseData, $tokenType);
                
                // Get updated token balances
                $tokenBalances = $this->tokenService->getUserTokens($userId);
                $subscriptionTokenBalance = $tokenBalances['subscription_token'];
                $addonsTokenBalance = $tokenBalances['addons_token'];
                
                $result = [
                    'success' => $success,
                    'subscription_token' => $subscriptionTokenBalance,
                    'addons_token' => $addonsTokenBalance
                ];
                
                return response()->json([
                    'success' => true,
                    'sessionId' => $session->id,
                    'sessionUrl' => $session->url, // URL to redirect the user to complete payment
                    'tokensAdded' => $tokensToAdd,
                    'subscription_token' => $subscriptionTokenBalance,
                    'addons_token' => $addonsTokenBalance
                ]);
            } catch (\Exception $e) {
                Log::error('Error updating user tokens: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to update user tokens: ' . $e->getMessage()
                ], 500);
            }
        } catch (ApiErrorException $e) {
            Log::error('Stripe error verifying session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Verification error: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error verifying session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => 'Verification error'
            ], 500);
        }
    }

    /**
     * Handle Stripe webhook events
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $endpointSecret = env('STRIPE_WEBHOOK_SECRET');

        
        try {
            // BYPASS SIGNATURE VERIFICATION FOR TESTING
            Log::info('Bypassing Stripe signature verification for testing');
            // Create event object directly without verification
            $event = new \Stripe\Event(json_decode($payload, true));
        } catch (\Exception $e) {
            Log::error('Error creating mock event: ' . $e->getMessage());
            return response()->json(['error' => 'Error creating mock event'], 400);
        }
    
        } catch (\UnexpectedValueException $e) {
            Log::error('Invalid payload: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Invalid signature: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // Handle the event
        Log::info('Webhook received: ' . $event->type);
        Log::info('Webhook payload: ' . json_encode($event->data->object, JSON_PRETTY_PRINT));
        
        // Always track that we processed this webhook event
        $this->logWebhookEvent($event);
        
        switch ($event->type) {
            case 'checkout.session.completed':
                $session = $event->data->object;
                
                // Only process if payment is successful
                if ($session->payment_status === 'paid') {
                    // Get metadata fields
                    $userId = $session->metadata->user_id ?? $session->metadata->userId ?? null;
                    $planId = $session->metadata->plan_id ?? $session->metadata->planId ?? null;
                    $action = $session->metadata->action ?? null;
                    $subscriptionId = $session->metadata->subscription_id ?? null;
                
                    Log::info("Webhook metadata check: user_id={$userId}, plan_id={$planId}, action={$action}");
                    
                    if ($userId) {
                        // Get price ID from the session
                        $lineItems = $session->line_items ?? null;
                        $priceId = null;
                        
                        if (!$lineItems) {
                            // Retrieve line items if not included
                            try {
                                $lineItems = \Stripe\Checkout\Session::retrieveLineItems($session->id);
                                $priceId = $lineItems->data[0]->price->id ?? null;
                            } catch (\Exception $e) {
                                Log::error('Error retrieving line items: ' . $e->getMessage());
                            }
                        } else {
                            // Extract price ID from the session line items
                            $priceId = $lineItems->data[0]->price->id ?? null;
                        }
                        
                        // If we couldn't get the price ID from line items, check metadata
                        if (!$priceId) {
                            $priceId = $session->metadata->priceId ?? null;
                        }
                        
                        // Check if this is a subscription
                        $isSubscription = $session->mode === 'subscription';
                        
                        // Process subscription or token purchase
                        if ($priceId) {
                            if ($isSubscription) {
                                // Check if this is a plan change or a new subscription
                                if ($action === 'plan_change' && $subscriptionId) {
                                    // Handle plan change
                                    try {
                                        Log::info("Processing plan change: subscription_id={$subscriptionId}, new_plan_id={$planId}");
                                        
                                        // Find subscription and new plan in our database
                                        $subscription = \App\Models\Subscription::where('id', $subscriptionId)->first();
                                        $newPlan = \App\Models\Plan::find($planId);
                                        
                                        if ($subscription && $newPlan) {
                                            // Retrieve updated stripe subscription
                                            $stripeSubId = $session->subscription ?? null;
                                            if ($stripeSubId) {
                                                // Update Stripe subscription ID if changed
                                                if ($subscription->stripe_subscription_id !== $stripeSubId) {
                                                    $subscription->stripe_subscription_id = $stripeSubId;
                                                    $subscription->save();
                                                }
                                            }
                                            
                                            // Update subscription in database
                                            $oldPlan = $subscription->plan;
                                            $subscription->plan_id = $newPlan->id;
                                            $subscription->save();
                                            
                                            // Add tokens if new plan has more tokens
                                            if ($newPlan->tokens_per_cycle > $oldPlan->tokens_per_cycle) {
                                                $tokenDifference = $newPlan->tokens_per_cycle - $oldPlan->tokens_per_cycle;
                                                
                                                $purchaseData = [
                                                    'sessionId' => $session->id,
                                                    'priceId' => $newPlan->stripe_price_id,
                                                    'amount' => $newPlan->price - $oldPlan->price,
                                                    'status' => 'completed',
                                                    'customerEmail' => $subscription->user->email,
                                                    'currency' => $newPlan->currency,
                                                    'type' => 'plan_change'
                                                ];
                                                
                                                $this->tokenService->updateUserTokens(
                                                    $subscription->user_id, 
                                                    $tokenDifference, 
                                                    $purchaseData
                                                );
                                            }
                                            
                                            Log::info("Plan change completed successfully: user #{$userId} changed from plan #{$oldPlan->id} to plan #{$newPlan->id}");
                                        } else {
                                            Log::error("Plan change failed: Could not find subscription with ID {$subscriptionId} or plan with ID {$planId}");
                                        }
                                    } catch (\Exception $e) {
                                        Log::error("Error processing plan change: " . $e->getMessage());
                                    }
                                } else {
                                    // Handle standard subscription creation from checkout
                                    try {
                                        $stripeSubId = $session->subscription ?? null;
                                        
                                        if ($planId && $stripeSubId) {
                                            // Retrieve subscription details from Stripe
                                            $stripeSubscription = \Stripe\Subscription::retrieve($stripeSubId);
                                            
                                            // Find user and plan
                                            $user = \App\Models\User::find($userId);
                                            $plan = \App\Models\Plan::find($planId);
                                            
                                            if ($user && $plan) {
                                                Log::info("Creating subscription for user #{$userId} on plan #{$planId}");
                                                
                                                // Calculate next billing date based on plan interval
                                                $nextBillingDate = $plan->interval === 'month' 
                                                    ? now()->addMonth() 
                                                    : now()->addYear();
                                                
                                                // Prepare subscription data
                                                $subscriptionData = [
                                                    'stripe_subscription_id' => $stripeSubId, // Fixed: Use $stripeSubId instead of $subscriptionId
                                                    'start_date' => now(),
                                                    'end_date' => $nextBillingDate,
                                                    'status' => 'active'
                                                ];
                                                
                                                // Create the subscription
                                                $result = $this->subscriptionService->createSubscription($user, $plan, $subscriptionData);
                                                
                                                if ($result['success']) {
                                                    Log::info("Successfully created subscription for user {$userId} with plan {$planId}");
                                                } else {
                                                    Log::error("Failed to create subscription: {$result['message']}");
                                                }
                                            } else {
                                                Log::error("Could not find user #{$userId} or plan #{$planId} for subscription creation");
                                            }
                                        } else {
                                            Log::error('Missing plan ID or subscription ID in metadata');
                                        }
                                    } catch (\Exception $e) {
                                        Log::error('Error processing subscription creation: ' . $e->getMessage());
                                    }
                                }
                            } else if (isset(self::TOKEN_AMOUNTS[$priceId]) || isset($session->metadata->tokenAmount)) {
                                // Process token purchase
                                $tokenAmount = self::TOKEN_AMOUNTS[$priceId] ?? (int)$session->metadata->tokenAmount;
                                
                                // Update user tokens
                                try {
                                    $purchaseData = [
                                        'sessionId' => $session->id,
                                        'priceId' => $priceId,
                                        'amount' => ($session->amount_total ?? 0) / 100, // Convert from cents
                                        'status' => 'completed',
                                        'customerEmail' => $session->customer_details->email ?? null,
                                        'currency' => $session->currency ?? 'usd',
                                        'type' => 'purchase'
                                    ];
                                    
                                    $this->tokenService->updateUserTokens($userId, $tokenAmount, $purchaseData);
                                    Log::info("Successfully added {$tokenAmount} tokens to user {$userId}");
                                } catch (\Exception $e) {
                                    Log::error('Error updating user tokens: ' . $e->getMessage());
                                }
                            } else {
                                Log::warning('Unknown price ID: ' . $priceId);
                            }
                        } else {
                            Log::error('Could not determine price ID from session');
                        }
                    } else {
                        Log::error('User ID not found in session metadata');
                    }
                    } else {
                        Log::info('Payment not successful: ' . $session->payment_status);
                    }
                break;
                
            // Subscription related events
            case 'customer.subscription.created':
                $subscription = $event->data->object;
                Log::info('New subscription created: ' . $subscription->id);
                
                try {
                    // Get user ID from metadata or customer metadata
                    $userId = $subscription->metadata->userId ?? null;
                    
                    if (!$userId && isset($subscription->customer)) {
                        // Try to get user ID from customer metadata
                        $customer = \Stripe\Customer::retrieve($subscription->customer);
                        $userId = $customer->metadata->userId ?? null;
                    }
                    
                    if ($userId) {
                        $planId = $subscription->metadata->planId ?? null;
                        
                        // Get plan if planId provided in metadata
                        if ($planId) {
                            $this->subscriptionService->handleSubscriptionCreated(
                                $userId,
                                $planId,
                                $subscription->id,
                                $subscription->current_period_end ? date('Y-m-d H:i:s', $subscription->current_period_end) : null,
                                $subscription->status
                            );
                        } else {
                            Log::error('Missing plan ID in subscription metadata: ' . $subscription->id);
                        }
                    } else {
                        Log::error('Could not determine user ID for subscription: ' . $subscription->id);
                    }
                    
                    // Call the updated subscription handler with the plan ID
                    $this->subscriptionService->handleSubscriptionUpdated(
                        $subscription->id,
                        $subscription->status,
                        $subscription->current_period_end ? date('Y-m-d H:i:s', $subscription->current_period_end) : null,
                        $subscription->cancel_at ? date('Y-m-d H:i:s', $subscription->cancel_at) : null,
                        $planId // Pass the extracted plan ID
                    );
                } catch (\Exception $e) {
                    Log::error('Error processing subscription update: ' . $e->getMessage());
                    Log::error('Exception stack trace: ' . $e->getTraceAsString());
                }
                break;
                
            case 'customer.subscription.deleted':
                $subscription = $event->data->object;
                Log::info('Subscription cancelled/deleted: ' . $subscription->id);
                
                try {
                    $this->subscriptionService->handleSubscriptionCancelled(
                        $subscription->id,
                        date('Y-m-d H:i:s')
                    );
                } catch (\Exception $e) {
                    Log::error('Error processing subscription cancellation: ' . $e->getMessage());
                }
                break;
                
            case 'invoice.payment_succeeded':
                $invoice = $event->data->object;
                
                // Only process if this is a subscription invoice
                if ($invoice->subscription) {
                    Log::info('Subscription payment succeeded for: ' . $invoice->subscription);
                    
                    try {
                        // Process subscription renewal
                        $this->subscriptionService->handleSuccessfulPayment(
                            $invoice->subscription,
                            $invoice->id,
                            ($invoice->amount_paid ?? 0) / 100,
                            $invoice->currency ?? 'usd'
                        );
                        
                        // Process renewal to handle any pending downgrades
                        $subscription = \App\Models\Subscription::where('stripe_subscription_id', $invoice->subscription)->first();
                        if ($subscription) {
                            Log::info('Processing subscription renewal and pending downgrades for: ' . $subscription->id);
                            $this->subscriptionService->processRenewal($subscription);
                        } else {
                            Log::warning('Subscription not found in our database: ' . $invoice->subscription);
                        }
                    } catch (\Exception $e) {
                        Log::error('Error processing subscription payment/renewal: ' . $e->getMessage());
                    }
                }
                break;
                
            case 'invoice.payment_failed':
                $invoice = $event->data->object;
                
                // Only process if this is a subscription invoice
                if ($invoice->subscription) {
                    Log::info('Subscription payment failed for: ' . $invoice->subscription);
                    
                    try {
                        // Handle failed payment
                        $this->subscriptionService->handleFailedPayment(
                            $invoice->subscription,
                            $invoice->id,
                            $invoice->attempt_count ?? 1
                        );
                    } catch (\Exception $e) {
                        Log::error('Error processing failed subscription payment: ' . $e->getMessage());
                    }
                }
                break;
            
            // Invoice related events
            case 'invoice.created':
                $invoice = $event->data->object;
                Log::info('New invoice created: ' . $invoice->id);
                // We just log these events for now - they're mostly informational
                break;
                
            case 'invoice.finalized':
                $invoice = $event->data->object;
                Log::info('Invoice finalized: ' . $invoice->id);
                // When an invoice is finalized, it's ready for payment
                break;
                
            case 'invoiceitem.created':
                $invoiceItem = $event->data->object;
                Log::info('Invoice item created: ' . $invoiceItem->id);
                // Track invoice items for detailed billing reports
                break;
                
            case 'customer.created':
                $customer = $event->data->object;
                Log::info('Customer created in Stripe: ' . $customer->id);
                // Store customer ID if needed
                break;
                
            case 'customer.updated':
                $customer = $event->data->object;
                Log::info('Customer updated in Stripe: ' . $customer->id);
                break;
                
            case 'customer.subscription.updated':
                $subscription = $event->data->object;
                
                // EMERGENCY DEBUG: Write directly to a file we can check
                $debugLog = "DEBUG: Webhook received at " . date('Y-m-d H:i:s') . "\n";
                $debugLog .= "Subscription ID: " . $subscription->id . "\n";
                $debugLog .= "Status: " . $subscription->status . "\n";
                
                if (isset($subscription->items->data[0]->plan->id)) {
                    $debugLog .= "Plan ID: " . $subscription->items->data[0]->plan->id . "\n";
                }
                
                file_put_contents(base_path('stripe_webhook_debug.txt'), $debugLog, FILE_APPEND);
                
                Log::info('Subscription updated in Stripe: ' . $subscription->id);
                
                try {
                    // Extract important data
                    $stripeSubscriptionId = $subscription->id;
                    $status = $subscription->status;
                    $nextBillingDate = $subscription->current_period_end 
                        ? date('Y-m-d H:i:s', $subscription->current_period_end) 
                        : null;
                    $cancelAt = $subscription->cancel_at 
                        ? date('Y-m-d H:i:s', $subscription->cancel_at) 
                        : null;
                    $planId = null;
                    
                    // Detailed logging for plan information
                    Log::info("Examining plan information in subscription update webhook...");
                    
                    // Log entire subscription object for comprehensive debugging
                    Log::info("Full subscription object: " . json_encode($subscription));
                    
                    // Log items data structure to debug
                    if (isset($subscription->items->data)) {
                        Log::info("Subscription items data: " . json_encode($subscription->items->data));
                        
                        // Loop through all items to find the plan
                        foreach ($subscription->items->data as $item) {
                            if (isset($item->plan->id)) {
                                $stripePlanId = $item->plan->id;
                                Log::info("Examining Stripe plan ID: {$stripePlanId}");
                                
                                // Try direct match on stripe_price_id
                                $plan = \App\Models\Plan::where('stripe_price_id', $stripePlanId)->first();
                                
                                // If not found, try partial match (sometimes Stripe adds prefixes/suffixes)
                                if (!$plan) {
                                    Log::info("No exact match found for {$stripePlanId}, trying partial match");
                                    $plan = \App\Models\Plan::whereRaw('stripe_price_id LIKE ?', ['%' . $stripePlanId . '%'])->first();
                                }
                                
                                if ($plan) {
                                    Log::info("Found matching plan: {$plan->id} for Stripe plan: {$stripePlanId}");
                                    Log::info("Plan details: name={$plan->name}, tokens_per_cycle={$plan->tokens_per_cycle}");
                                    $planId = $plan->id;
                                    break; // Stop after finding the first match
                                }
                            }
                        }
                        
                        // If still not found, fallback to product ID matching
                        if (!$planId && isset($subscription->items->data[0]->price->product)) {
                            $productId = $subscription->items->data[0]->price->product;
                            Log::info("Attempting to match by product ID: {$productId}");
                            
                            // Some plans might store product ID in metadata or other fields
                            // Check all plans to see if any match this product
                            $allPlans = \App\Models\Plan::all();
                            foreach ($allPlans as $possiblePlan) {
                                // Log plan details for debugging
                                Log::info("Checking plan {$possiblePlan->id}: stripe_price_id={$possiblePlan->stripe_price_id}, name={$possiblePlan->name}");
                                
                                // Check if any identifying information matches
                                if ($possiblePlan->stripe_product_id == $productId || 
                                    strpos($possiblePlan->stripe_price_id, $productId) !== false || 
                                    (isset($possiblePlan->metadata) && isset($possiblePlan->metadata['product_id']) && 
                                     $possiblePlan->metadata['product_id'] == $productId)) {
                                    
                                    Log::info("Found plan match via product ID: {$possiblePlan->id}");
                                    $planId = $possiblePlan->id;
                                    break;
                                }
                            }
                        }
                        
                        if (!$planId) {
                            Log::error("No matching plan found in database for subscription items");
                            // List available plans for debugging
                            $availablePlans = \App\Models\Plan::select('id', 'name', 'stripe_price_id', 'tokens_per_cycle')->get();
                            Log::info("Available plans in database: " . json_encode($availablePlans));
                        }
                    } else {
                        Log::warning("No items data found in subscription object");
                    }
                    
                    // If we found a plan ID, proceed with update
                    if ($planId) {
                        Log::info("Calling handleSubscriptionUpdated with: stripeSubId={$stripeSubscriptionId}, status={$status}, planId={$planId}");
                        $result = $this->subscriptionService->handleSubscriptionUpdated(
                            $stripeSubscriptionId,
                            $status,
                            $nextBillingDate,
                            $cancelAt,
                            $planId
                        );
                        Log::info("Subscription update handler result: " . ($result ? 'SUCCESS' : 'FAILED'));
                    } else {
                        // Special case: If no plan was found but we have subscription ID, still update the status/dates
                        Log::warning("No plan ID found, but continuing with subscription update for status/dates only");
                        $result = $this->subscriptionService->handleSubscriptionUpdated(
                            $stripeSubscriptionId,
                            $status,
                            $nextBillingDate,
                            $cancelAt,
                            null // No plan ID to update
                        );
                        Log::info("Status-only update result: " . ($result ? 'SUCCESS' : 'FAILED'));
                    }
                } catch (\Exception $e) {
                    Log::error('Error processing subscription update: ' . $e->getMessage());
                    Log::error('Exception stack trace: ' . $e->getTraceAsString());
                }
                break;
                
            // Add other event types as needed
            
            default:
                Log::info('Unhandled event type: ' . $event->type);
        }

        return response()->json(['success' => true]);
    }
    
    /**
     * List purchases for a user
     */
    /**
     * Log a webhook event for debugging and audit purposes
     * 
     * @param \Stripe\Event $event
     * @return void
     */
    protected function logWebhookEvent($event)
    {
        try {
            // You can store this in the database if you want a permanent record
            // For now, we'll just log it with more structured information
            $eventData = [
                'id' => $event->id,
                'type' => $event->type,
                'created' => date('Y-m-d H:i:s', $event->created),
                'data' => json_encode($event->data->object),
            ];
            
            // Add additional info for specific event types
            if (strpos($event->type, 'subscription') !== false && isset($event->data->object->id)) {
                $eventData['stripe_subscription_id'] = $event->data->object->id;
            }
            
            if (strpos($event->type, 'invoice') !== false && isset($event->data->object->subscription)) {
                $eventData['stripe_subscription_id'] = $event->data->object->subscription;
            }
            
            Log::info('Webhook event logged', $eventData);
        } catch (\Exception $e) {
            Log::error('Failed to log webhook event: ' . $e->getMessage());
        }
    }

    public function listPurchases(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100',
                'page' => 'nullable|integer|min:1',
                'type' => 'nullable|string|in:purchase,usage,all'
            ]);
            
            // Get the authenticated user's ID
            $userId = auth()->id();
            
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Authenticated user not found'
                ], 401);
            }
            
            $limit = $validated['limit'] ?? 10;
            $page = $validated['page'] ?? 1;
            $type = $validated['type'] ?? 'all';
            
            // Get purchases from database
            $query = \App\Models\Purchase::where('user_id', $userId);
            
            // Filter by type if specified
            if ($type !== 'all') {
                $query->where('type', $type);
            }
            
            // Order by most recent first
            $query->orderBy('created_at', 'desc');
            
            // Paginate results
            $purchases = $query->paginate($limit, ['*'], 'page', $page);
            
            return response()->json([
                'success' => true,
                'data' => $purchases
            ]);
        } catch (\Exception $e) {
            Log::error('Error listing purchases: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to list purchases: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a Stripe Customer Portal session for managing subscriptions
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createBillingPortalSession(Request $request)
    {
        try {
            // Validate request
            $validated = $request->validate([
                'customer_id' => 'sometimes|string',
                'return_url' => 'sometimes|string|url',
            ]);

            // Get the authenticated user
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }

            // Get customer ID - either from request, or find it
            $customerId = $validated['customer_id'] ?? null;
            
            // If no customer ID provided, try to find it
            if (!$customerId) {
                try {
                    // Search for existing customers with this email
                    $customers = \Stripe\Customer::all([
                        'email' => $user->email,
                        'limit' => 1
                    ]);
                    
                    if (count($customers->data) > 0) {
                        $customerId = $customers->data[0]->id;
                    } else {
                        return response()->json([
                            'success' => false,
                            'message' => 'No Stripe customer found for this user'
                        ], 404);
                    }
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    Log::error('Error finding customer: ' . $e->getMessage());
                    return response()->json([
                        'success' => false,
                        'message' => 'Error finding Stripe customer: ' . $e->getMessage()
                    ], 500);
                }
            }

            // Set return URL
            $returnUrl = $validated['return_url'] ?? env('FRONTEND_URL') . '/billing';

            // Create billing portal session
            $session = \Stripe\BillingPortal\Session::create([
                'customer' => $customerId,
                'return_url' => $returnUrl,
            ]);

            return response()->json([
                'success' => true,
                'url' => $session->url
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe error creating billing portal session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create billing portal session: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error creating billing portal session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create billing portal session'
            ], 500);
        }
    }

    /**
     * Get all transactions from Stripe for admin users
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllTransactions(Request $request)
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100',
                'starting_after' => 'nullable|string',
                'ending_before' => 'nullable|string',
                'created' => 'nullable|array',
                'type' => 'nullable|string|in:payment_intent,charge,payment,refund,all',
                'customer' => 'nullable|string',
                'status' => 'nullable|string'
            ]);
            
            $limit = $validated['limit'] ?? 10;
            $type = $validated['type'] ?? 'all';
            
            // Prepare parameters for Stripe API
            $params = [
                'limit' => $limit,
            ];
            
            // Add pagination parameters if provided
            if (isset($validated['starting_after'])) {
                $params['starting_after'] = $validated['starting_after'];
            }
            
            if (isset($validated['ending_before'])) {
                $params['ending_before'] = $validated['ending_before'];
            }
            
            // Add date filter if provided
            if (isset($validated['created'])) {
                $params['created'] = $validated['created'];
            }
            
            // Add customer filter if provided
            if (isset($validated['customer'])) {
                $params['customer'] = $validated['customer'];
            }
            
            // Add status filter if provided
            if (isset($validated['status'])) {
                $params['status'] = $validated['status'];
            }
            
            // Fetch data from Stripe based on the requested type
            $result = [];
            
            if ($type === 'all' || $type === 'charge') {
                Log::info("Get Stripe Charge");
                $charges = \Stripe\Charge::all($params);
                $result['charges'] = $charges->data;
                $result['has_more_charges'] = $charges->has_more;
                $result['charges_count'] = count($charges->data);
            }
            
            if ($type === 'all' || $type === 'payment_intent') {
                Log::info("Get Stripe Payment Intent");
                $paymentIntents = \Stripe\PaymentIntent::all($params);
                $result['payment_intents'] = $paymentIntents->data;
                $result['has_more_payment_intents'] = $paymentIntents->has_more;
                $result['payment_intents_count'] = count($paymentIntents->data);
            }
            
            if ($type === 'all' || $type === 'payment') {
                Log::info("Get Stripe Payment Method");
                $payments = \Stripe\PaymentMethod::all($params);
                $result['payments'] = $payments->data;
                $result['has_more_payments'] = $payments->has_more;
                $result['payments_count'] = count($payments->data);
            }
            
            if ($type === 'all' || $type === 'refund') {
                Log::info("Get Stripe Refund");
                $refunds = \Stripe\Refund::all($params);
                $result['refunds'] = $refunds->data;
                $result['has_more_refunds'] = $refunds->has_more;
                $result['refunds_count'] = count($refunds->data);
            }
            
            // Also fetch local database records
            $query = \App\Models\Purchase::query();
            
            // Order by most recent first
            $query->orderBy('created_at', 'desc');
            
            // Paginate results
            $purchases = $query->paginate($limit, ['*'], 'page', $request->page ?? 1);
            $result['local_records'] = $purchases;
            
            $subscriptions = [];
            $subscriptionsResponse = $this->getUserWithSubscriptions();
            if ($subscriptionsResponse->original['success']) {
                $subscriptions = $subscriptionsResponse->original['data'];
            } 

            $result['subscriptions'] = $subscriptions;

            return response()->json([
                'success' => true,
                'data' => $result
            ]);
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe error fetching transactions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch Stripe transactions: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error fetching transactions: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch transactions: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get all invoices from Stripe for admin users
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllInvoices(Request $request)
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100',
                'starting_after' => 'nullable|string',
                'ending_before' => 'nullable|string',
                'customer' => 'nullable|string',
                'status' => 'nullable|string|in:draft,open,paid,uncollectible,void',
                'subscription' => 'nullable|string',
                'created' => 'nullable|array',
                'due_date' => 'nullable|array',
            ]);
            
            $limit = $validated['limit'] ?? 25;
            
            // Prepare parameters for Stripe API
            $params = [
                'limit' => $limit,
                'expand' => ['data.customer', 'data.subscription']
            ];
            
            // Add pagination parameters if provided
            if (isset($validated['starting_after'])) {
                $params['starting_after'] = $validated['starting_after'];
            }
            
            if (isset($validated['ending_before'])) {
                $params['ending_before'] = $validated['ending_before'];
            }
            
            // Add filters if provided
            foreach (['customer', 'status', 'subscription', 'created', 'due_date'] as $filter) {
                if (isset($validated[$filter])) {
                    $params[$filter] = $validated[$filter];
                }
            }
            
            // Fetch invoices from Stripe
            $invoices = \Stripe\Invoice::all($params);
            
            // Return response with pagination metadata
            return response()->json([
                'success' => true,
                'data' => [
                    'invoices' => $invoices->data,
                    'has_more' => $invoices->has_more,
                    'count' => count($invoices->data),
                    'url' => $invoices->url
                ]
            ]);
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('Stripe error fetching invoices: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch Stripe invoices: ' . $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            Log::error('Error fetching invoices: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch invoices: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getInvoice(Request $request, $id) {
        $invoice = \Stripe\Invoice::retrieve($id);
        return response()->json([
            'success' => true,
            'message' => "Invoice retrieve successful",
            'data' => $invoice
        ]);
    }

    public function getUserWithSubscriptions($current_period_start = null, $current_period_end = null) {
        $subscriptions = $this->getAllSubscriptions($current_period_start, $current_period_end);
        return response()->json([
            'success' => true,
            'message' => "Subscriptions retrieve successful",
            'data' => $subscriptions
        ]);
    }

    public function getPayments($current_period_start = null, $current_period_end = null) {
        $charges = $this->getAllSuccessfulCharges($current_period_start, $current_period_end);
        return response()->json([
            'success' => true,
            'message' => "Charge retrieve successful",
            'data' => $charges
        ]);
    }

    public function getAllSubscriptions($current_period_start = null, $current_period_end = null, $limit = 100) {
        $allSubscriptions = [];
        $lastSubscriptionId = null;

        do {
            $params = [
                'limit' => $limit,
                'status' => 'active'
            ];

            // Stripe Subscriptions don't support "created" directly
            // If you want to filter by time, you can filter manually after fetching
            if ($lastSubscriptionId) {
                $params['starting_after'] = $lastSubscriptionId;
            }

            $subscriptions = \Stripe\Subscription::all($params);

            // Merge current batch
            $allSubscriptions = array_merge($allSubscriptions, $subscriptions->data);

            // prepare for next page
            $lastSubscriptionId = !empty($subscriptions->data) ? end($subscriptions->data)->id : null;

        } while ($subscriptions->has_more);

        // ✅ Filter manually by current_period_start/current_period_end
        if ($current_period_start || $current_period_end) {
            $allSubscriptions = array_filter($allSubscriptions, function ($sub) use ($current_period_start, $current_period_end) {
                $start = $current_period_start ? strtotime($current_period_start) : null;
                $end   = $current_period_end ? strtotime($current_period_end) : null;
                $created = $sub->created;

                if ($start && $created < $start) return false;
                if ($end && $created > $end) return false;

                return true;
            });
        }

        return array_values($allSubscriptions);
    }

    public function getAllSuccessfulCharges($current_period_start = null, $current_period_end = null, $limit = 100) {
        $allCharges = [];
        $lastChargeId = null;

        do {
            $params = [
                'limit' => $limit,
                'status' => 'succeeded',
                'created' => [
                    'gte' => $current_period_start ? strtotime($current_period_start) : null,
                    'lte' => $current_period_end ? strtotime($current_period_end) : null,
                ],
            ];

            if ($lastChargeId) {
                $params['starting_after'] = $lastChargeId;
            }

            $charges = \Stripe\Charge::all($params);

            // Merge current batch
            $allCharges = array_merge($allCharges, $charges->data);

            // prepare for next page
            $lastChargeId = end($charges->data)->id ?? null;

        } while ($charges->has_more);

        return $allCharges;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\StripeController;

class SubscriptionController extends Controller
{
    /**
     * The subscription service instance.
     *
     * @var \App\Services\SubscriptionService
     */
    protected $subscriptionService;

    /**
     * Create a new controller instance.
     *
     * @param \App\Services\SubscriptionService $subscriptionService
     * @return void
     */
    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * Initiate a subscription by creating a Stripe checkout session.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function initiateSubscription(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'plan_id' => 'required|exists:plans,id',
            ]);
            
            $user = Auth::user();
            $plan = Plan::findOrFail($request->plan_id);
            
            // Check if user already has an active subscription
            if ($user->hasActiveSubscription()) {
                return response()->json([
                    'success' => false,
                    'message' => 'User already has an active subscription'
                ], 400);
            }
            
            // Check if this is an annual plan and the user doesn't have prior paid subscription
            if (strpos(strtolower($plan->interval), 'year') !== false && !$user->hasActiveSubscription()) {
                // Get user's subscription history
                $hasHadPaidSubscription = \App\Models\Subscription::where('user_id', $user->id)
                    ->where('status', 'active')
                    ->orWhere('status', 'canceled')
                    ->exists();
                    
                if (!$hasHadPaidSubscription) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You must subscribe to a monthly plan before accessing yearly promotions.'
                    ], 403);
                }
            }
            
            if (!$plan->stripe_price_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'This plan does not have a valid Stripe price ID'
                ], 400);
            }
            
            try {
                // Set API key explicitly
                \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
                
                // Prepare customer data
                $customerId = null;
                try {
                    // First check if user already has a stripe_customer_id
                    if (!empty($user->stripe_customer_id)) {
                        $customerId = $user->stripe_customer_id;
                        // Verify customer still exists in Stripe
                        try {
                            $customer = \Stripe\Customer::retrieve($customerId);
                            // Update customer details
                            \Stripe\Customer::update($customerId, [
                                'name' => $user->name,
                                'email' => $user->email
                            ]);
                        } catch (\Exception $e) {
                            // Customer not found, will create a new one
                            Log::warning('Stored Stripe customer not found: ' . $e->getMessage());
                            $customerId = null;
                        }
                    }
                    
                    // If no valid customer ID yet, try to find by email or create new
                    if (!$customerId) {
                        // Search for existing customers with this email
                        $customers = \Stripe\Customer::all([
                            'email' => $user->email,
                            'limit' => 1
                        ]);
                        
                        if (count($customers->data) > 0) {
                            // Update existing customer
                            $customerId = $customers->data[0]->id;
                            \Stripe\Customer::update($customerId, [
                                'name' => $user->name
                            ]);
                        } else {
                            // Create new customer
                            $customer = \Stripe\Customer::create([
                                'email' => $user->email,
                                'name' => $user->name,
                                'metadata' => [
                                    'userId' => (string)$user->id
                                ]
                            ]);
                            $customerId = $customer->id;
                        }
                        
                        // Save the customer ID to our user model
                        $user->stripe_customer_id = $customerId;
                        $user->save();
                        
                        Log::info("Updated user {$user->id} with Stripe customer ID: {$customerId}");
                    }
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    Log::error('Error creating/updating customer: ' . $e->getMessage());
                    // Continue without customer ID if there's an error
                }
                
                // Create checkout session for subscription
                $sessionConfig = [
                    'mode' => 'subscription',  // Explicitly set to subscription
                    'payment_method_types' => ['card'],
                    'line_items' => [
                        [
                            'price' => $plan->stripe_price_id,
                            'quantity' => 1,
                        ],
                    ],
                    'success_url' => env('FRONTEND_URL') . '/membership-plan?session_id={CHECKOUT_SESSION_ID}',
                    'cancel_url' => env('FRONTEND_URL') . '/membership-plan',
                    'metadata' => [
                        'user_id' => (string)$user->id,
                        'plan_id' => (string)$plan->id,
                    ],
                ];
                
                // Add customer to session config
                if ($customerId) {
                    $sessionConfig['customer'] = $customerId;
                }
                
                // Create the session
                $session = \Stripe\Checkout\Session::create($sessionConfig);
                
                return response()->json([
                    'success' => true,
                    'id' => $session->id,
                    'sessionUrl' => $session->url,
                ]);
                
            } catch (\Stripe\Exception\ApiErrorException $e) {
                Log::error('Stripe error: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create checkout session: ' . $e->getMessage(),
                ], 500);
            }
            
        } catch (\Exception $e) {
            Log::error('Error in initiateSubscription: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to initiate subscription',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all available subscription plans.
     *
     * @return JsonResponse
     */
    public function getPlans(): JsonResponse
    {
        try {
            // check if user have free plan before, if user subscribe free plan before, and not using free plan now, show all plan except free
            $user = User::find(Auth::user()->id);
            $user_subscription = Subscription::where('user_id', $user->id)->first();
            $free_plan = Plan::where('name', 'Free')->first();
            if ($user->free_plan_used == true && $user_subscription->plan_id != $free_plan->id) {
                $plans = Plan::where('name', '<>', 'Free')->get();
            } else {
                $plans = Plan::orderBy('price')->get();
            }
            
            return response()->json([
                'success' => true,
                'plans' => $plans
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve plans',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Subscribe a user to a plan.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function subscribe(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'plan_id' => 'required|exists:plans,id',
            ]);

            $user = Auth::user();
            $plan = Plan::findOrFail($request->plan_id);

            $result = $this->subscriptionService->createSubscription($user, $plan);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Subscription created successfully',
                    'subscription' => $result['subscription']
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Error in SubscriptionController@subscribe: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create subscription',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel the user's subscription by redirecting to Stripe customer portal.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function cancel(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'subscription_id' => 'required|integer'
            ]);
            
            // Find the subscription by ID
            $subscription = Subscription::find($request->subscription_id);
            
            if (!$subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription not found'
                ], 404);
            }
            
            // Verify ownership or admin privileges
            $user = Auth::user();
            if ($subscription->user_id !== $user->id && !$user->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to cancel this subscription'
                ], 403);
            }
            
            // Cancel the subscription
            $result = $this->subscriptionService->cancelSubscription($subscription);
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Subscription canceled successfully',
                    'subscription' => $result['subscription'] ?? null
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 400);
            }
            
        } catch (\Exception $e) {
            Log::error('Error in SubscriptionController@cancel: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel subscription',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change the subscription plan by redirecting the user to the Stripe Customer Portal.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function changePlan(Request $request): JsonResponse
    {
        
        try {
            $request->validate([
                'plan_id' => 'required|exists:plans,id'
            ]);
            
            $user = Auth::user();
            $subscription = $user->subscription;
            
            if (!$subscription) {
                Log::info("User don't have active subscription");
                return response()->json([
                    'success' => false,
                    'message' => 'No active subscription found'
                ], 404);
            }
            $newPlan = Plan::findOrFail($request->plan_id);
            // Don't allow changing to the same plan
            if ($subscription->plan_id == $newPlan->id) {
                Log::info("Same plan found");
                return response()->json([
                    'success' => false,
                    'message' => 'You are already subscribed to this plan'
                ], 400);
            }

            if (empty($newPlan->stripe_price_id)) {
                Log::info("No stripe price id");
                return response()->json([
                    'success' => false,
                    'message' => 'This plan does not have a valid Stripe price ID'
                ], 400);
            }
            
            // Process the plan change using our enhanced service
            $result = $this->subscriptionService->changePlan($subscription, $newPlan);
            // Return appropriate response based on upgrade or downgrade
            if (isset($result['type'])) {
                if ($result['type'] === 'upgrade') {
                    return response()->json([
                        'success' => true,
                        'message' => 'Your subscription has been upgraded successfully!',
                        'invoice_url' => $result['invoice_url'] ?? null,
                        'prorated_amount' => $result['prorated_amount'] ?? 0,
                        'details' => $result
                    ]);
                } elseif ($result['type'] === 'downgrade') {
                    return response()->json([
                        'success' => true,
                        'message' => 'Your subscription downgrade is scheduled for the next billing cycle on ' . $result['effective_date'],
                        'effective_date' => $result['effective_date'] ?? null,
                        'details' => $result
                    ]);
                } else {
                    // Same price plan change
                    return response()->json([
                        'success' => true,
                        'message' => 'Your subscription plan has been changed successfully!',
                        'details' => $result
                    ]);
                }
            }
            
            // Fallback response if type is not set
            return response()->json([
                'success' => true,
                'message' => 'Subscription plan changed successfully',
                'details' => $result
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error in SubscriptionController@changePlan: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to change subscription plan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get or create a Stripe customer ID for a user.
     *
     * @param User $user
     * @return string
     */
    private function getOrCreateStripeCustomerId(User $user): string
    {
        try {
            // Search for existing customers with this email
            $customers = \Stripe\Customer::all([
                'email' => $user->email,
                'limit' => 1
            ]);
            
            if (count($customers->data) > 0) {
                // Return existing customer ID
                return $customers->data[0]->id;
            } else {
                // Create new customer
                $customer = \Stripe\Customer::create([
                    'email' => $user->email,
                    'name' => $user->name,
                    'metadata' => [
                        'userId' => (string)$user->id
                    ]
                ]);
                
                return $customer->id;
            }
        } catch (\Exception $e) {
            Log::error('Error creating/retrieving Stripe customer: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get the user's active subscription.
     *
     * @return JsonResponse
     */
    public function getUserSubscription(): JsonResponse
    {
        try {
            $user = Auth::user();
            $subscription = $user->subscription;
            
            if (!$subscription) {
                return response()->json([
                    'success' => true,
                    'hasSubscription' => false
                ]);
            }
            
            // Load the plan relationship
            $subscription->load('plan');
            
            return response()->json([
                'success' => true,
                'hasSubscription' => true,
                'subscription' => $subscription
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve subscription',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resume a previously canceled subscription if it hasn't ended yet.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function resume(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            $subscription = $user->subscription;
            
            if (!$subscription) {
                return response()->json([
                    'success' => false,
                    'message' => 'No subscription found'
                ], 404);
            }
            
            if ($subscription->status !== 'canceled' || !$subscription->canceled_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription is not canceled'
                ], 400);
            }
            
            if ($subscription->ends_at && now()->gt($subscription->ends_at)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Subscription has already ended and cannot be resumed'
                ], 400);
            }
            
            // Resume the subscription in Stripe
            try {
                \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
                
                // If there's a Stripe subscription, reactivate it
                if ($subscription->stripe_subscription_id) {
                    \Stripe\Subscription::update($subscription->stripe_subscription_id, [
                        'cancel_at_period_end' => false,
                    ]);
                }
                
                // Update local subscription record
                $subscription->status = 'active';
                $subscription->canceled_at = null;
                $subscription->save();
                
                return response()->json([
                    'success' => true,
                    'message' => 'Subscription resumed successfully',
                    'subscription' => $subscription
                ]);
                
            } catch (\Stripe\Exception\ApiErrorException $e) {
                Log::error('Stripe error in resuming subscription: ' . $e->getMessage());
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to resume subscription with Stripe: ' . $e->getMessage()
                ], 500);
            }
            
        } catch (\Exception $e) {
            Log::error('Error in SubscriptionController@resume: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to resume subscription',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all subscriptions (Admin only).
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $subscriptions = Subscription::with(['user', 'plan'])
                ->orderBy('created_at', 'desc')
                ->paginate(20);
            
            return response()->json([
                'success' => true,
                'subscriptions' => $subscriptions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve subscriptions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process a webhook for subscription events.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sig_header = $request->header('Stripe-Signature');
        $endpoint_secret = env('STRIPE_WEBHOOK_SECRET');
        \Log::info('Webhook called : '.json_encode($request->all()));
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
            \Log::info('Webhook type : '.$event->type);
            
            // Handle the event
            switch ($event->type) {
                case 'customer.subscription.updated':
                    $subscription = $event->data->object;
                    // Update the subscription in your database
                    $this->subscriptionService->updateSubscriptionStatus($subscription);
                    break;
                case 'customer.subscription.deleted':
                    $subscription = $event->data->object;
                    // Mark the subscription as canceled in your database
                    $this->subscriptionService->handleSubscriptionCanceled($subscription);
                    break;
                default:
                    // Unexpected event type
                    Log::info('Unhandled event type: ' . $event->type);
            }
            
            return response()->json(['success' => true]);
            
        } catch(\UnexpectedValueException $e) {
            Log::error('Invalid payload: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Invalid signature: ' . $e->getMessage());
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Error processing webhook: ' . $e->getMessage());
            return response()->json(['error' => 'Error processing webhook'], 500);
        }
    }

    /**
     * Verify and process a completed checkout session.
     * This is useful for manually processing sessions when webhooks fail.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function verifyCheckoutSession(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'session_id' => 'required|string'
            ]);
            
            \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
            
            // Retrieve the session from Stripe
            $session = \Stripe\Checkout\Session::retrieve([
                'id' => $request->session_id,
                'expand' => ['subscription', 'customer']
            ]);
            
            // Verify payment status
            if ($session->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not completed',
                    'status' => $session->payment_status
                ], 400);
            }
            
            // Get metadata
            $userId = $session->metadata->user_id ?? $session->metadata->userId ?? null;
            $planId = $session->metadata->plan_id ?? $session->metadata->planId ?? null;
            
            Log::info('Extracted metadata - userId: ' . $userId . ', planId: ' . $planId);
            
            if (!$userId || !$planId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Missing user ID or plan ID in session metadata',
                    'metadata' => $session->metadata
                ], 400);
            }
            
            // Find user and plan
            $user = \App\Models\User::find($userId);
            $plan = \App\Models\Plan::find($planId);
            
            if (!$user || !$plan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid user ID or plan ID',
                    'userId' => $userId,
                    'planId' => $planId
                ], 400);
            }
            
            // Get subscription ID from the session
            $subscriptionId = $session->subscription;
            
            if (is_object($subscriptionId)) {
                $subscriptionId = $subscriptionId->id;
            }
            
            if (!$subscriptionId) {
                return response()->json([
                    'success' => false,
                    'message' => 'No subscription created for this session'
                ], 400);
            }
            
            // Check if subscription already exists
            $existingSubscription = \App\Models\Subscription::where('stripe_subscription_id', $subscriptionId)->first();
            
            if ($existingSubscription) {
                return response()->json([
                    'success' => true,
                    'message' => 'Subscription already exists',
                    'subscription' => $existingSubscription
                ]);
            }
            
            // Get subscription details from Stripe
            $stripeSubscription = \Stripe\Subscription::retrieve([
                'id' => $subscriptionId,
                'expand' => ['items.data.price.product']
            ]);
            
            // Calculate next billing date
            $nextBillingDate = now()->addSeconds($stripeSubscription->current_period_end - $stripeSubscription->current_period_start);
            
            // Create the subscription data
            $subscriptionData = [
                'stripe_subscription_id' => $subscriptionId,
                'status' => 'active',
                'next_billing_date' => $nextBillingDate,
                'canceled_at' => null,
                'ends_at' => null,
            ];
            
            // Use subscription service to create the subscription
            $subscriptionService = app()->make(\App\Services\SubscriptionService::class);
            $result = $subscriptionService->createSubscription($user, $plan, $subscriptionData);
            
            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Subscription created successfully',
                    'subscription' => $result['subscription'] ?? null
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => $result['message']
                ], 500);
            }
            
        } catch (\Exception $e) {
            Log::error('Error in verifyCheckoutSession: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify checkout session: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Create a customer portal session to allow users to manage their subscriptions
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function createCustomerPortalSession(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();
            
            if (!$user->stripe_customer_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not a Stripe customer'
                ], 404);
            }
            
            $returnUrl = $request->input('return_url', env('FRONTEND_URL') . '/account');
            
            \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
            
            // Create a portal session for the customer
            $session = \Stripe\BillingPortal\Session::create([
                'customer' => $user->stripe_customer_id,
                'return_url' => $returnUrl,
            ]);
            
            return response()->json([
                'success' => true,
                'url' => $session->url
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error creating customer portal session: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Could not create customer portal session: ' . $e->getMessage()
            ], 500);
        }
    }
}

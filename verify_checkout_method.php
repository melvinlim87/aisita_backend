<?php
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
            'session_id' => 'required|string',
        ]);
        
        $sessionId = $request->session_id;
        
        // Set API key
        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
        
        // Retrieve session
        $session = \Stripe\Checkout\Session::retrieve([
            'id' => $sessionId,
            'expand' => ['subscription', 'line_items'],
        ]);
        
        Log::info('Verifying checkout session: ' . $sessionId);
        Log::info('Session details: ' . json_encode($session, JSON_PRETTY_PRINT));
        
        if ($session->payment_status !== 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Payment not completed for this session',
                'session' => [
                    'id' => $session->id,
                    'payment_status' => $session->payment_status
                ]
            ], 400);
        }
        
        // Check if subscription mode
        if ($session->mode !== 'subscription') {
            return response()->json([
                'success' => false,
                'message' => 'Not a subscription checkout session',
                'session' => [
                    'id' => $session->id,
                    'mode' => $session->mode
                ]
            ], 400);
        }
        
        // Get metadata
        $userId = $session->metadata->user_id ?? $session->metadata->userId ?? null;
        $planId = $session->metadata->plan_id ?? $session->metadata->planId ?? null;
        
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
        
        // Get subscription ID
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
        
        // Calculate end date based on plan interval
        $nextBillingDate = $plan->interval === 'month' 
            ? now()->addMonth() 
            : now()->addYear();
        
        // Check if subscription already exists
        $existingSubscription = \App\Models\Subscription::where('stripe_subscription_id', $subscriptionId)->first();
        
        if ($existingSubscription) {
            return response()->json([
                'success' => true,
                'message' => 'Subscription already exists',
                'subscription' => $existingSubscription
            ]);
        }
        
        // Create the subscription
        $subscriptionData = [
            'stripe_subscription_id' => $subscriptionId,
            'start_date' => now(),
            'end_date' => $nextBillingDate,
            'status' => 'active'
        ];
        
        // Use subscription service
        $subscriptionService = app()->make(\App\Services\SubscriptionService::class);
        $result = $subscriptionService->createSubscription($user, $plan, $subscriptionData);
        
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
            ], 500);
        }
        
    } catch (\Exception $e) {
        Log::error('Error in verifyCheckoutSession: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to verify checkout session: ' . $e->getMessage()
        ], 500);
    }
}

<?php
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
            'mode' => 'sometimes|string|in:payment,subscription',
            'metadata' => 'sometimes|array',
            'customerInfo.name' => 'nullable|string',
            'customerInfo.email' => 'nullable|email',
        ]);

        $priceId = $validated['priceId'];
        $userId = $validated['userId'];
        // Define mode with a default value
        $mode = $validated['mode'] ?? $request->input('mode', 'payment');
        // Get metadata from validated data or request
        $metadata = $validated['metadata'] ?? $request->input('metadata', []);
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
            'mode' => $mode,  // Use the mode variable we defined
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
        ];

        // Only add payment_intent_data for payment mode (not for subscriptions)
        if ($mode === 'payment') {
            $sessionConfig['payment_intent_data'] = [
                'metadata' => [
                    'userId' => $userId,
                    'price_id' => $priceId,
                    'session_id' => '{CHECKOUT_SESSION_ID}',
                    'customer_name' => $customerInfo['name'] ?? '',
                    'customer_email' => $customerInfo['email'] ?? '',
                ]
            ];
        }
        
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
            'url' => $session->url
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
            'message' => 'Failed to create checkout session: ' . $e->getMessage()
        ], 500);
    }
}

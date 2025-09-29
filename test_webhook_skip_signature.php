<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\StripeController;

echo "=== Stripe Webhook Signature Bypass Test ===\n";

// Create log file
file_put_contents(__DIR__.'/webhook_signature_test.log', date('Y-m-d H:i:s') . " - Test started\n", FILE_APPEND);

try {
    // Check the current webhook secret
    $webhookSecret = env('STRIPE_WEBHOOK_SECRET');
    echo "Current webhook secret: " . ($webhookSecret ? substr($webhookSecret, 0, 5) . '...' : 'Not set') . "\n";
    file_put_contents(__DIR__.'/webhook_signature_test.log', "Current webhook secret: " . ($webhookSecret ? substr($webhookSecret, 0, 5) . '...' : 'Not set') . "\n", FILE_APPEND);
    
    // Mock a subscription updated event payload
    $payload = '{
      "id": "evt_test_webhook_signature",
      "object": "event",
      "api_version": "2020-08-27",
      "created": ' . time() . ',
      "data": {
        "object": {
          "id": "sub_test123456",
          "object": "subscription",
          "application": null,
          "application_fee_percent": null,
          "automatic_tax": {
            "enabled": false
          },
          "billing_cycle_anchor": ' . time() . ',
          "billing_thresholds": null,
          "cancel_at": null,
          "cancel_at_period_end": false,
          "canceled_at": null,
          "collection_method": "charge_automatically",
          "created": ' . time() . ',
          "currency": "usd",
          "current_period_end": ' . (time() + 2592000) . ',
          "current_period_start": ' . time() . ',
          "customer": "cus_test123456",
          "days_until_due": null,
          "default_payment_method": null,
          "default_source": null,
          "default_tax_rates": [],
          "description": null,
          "discount": null,
          "ended_at": null,
          "items": {
            "object": "list",
            "data": [
              {
                "id": "si_test123456",
                "object": "subscription_item",
                "billing_thresholds": null,
                "created": ' . time() . ',
                "plan": {
                  "id": "price_1S2sxj2MDBrgqcCAmE4CiNVz",
                  "object": "plan",
                  "active": true,
                  "amount": 4900,
                  "amount_decimal": "4900",
                  "billing_scheme": "per_unit",
                  "created": ' . time() . ',
                  "currency": "usd",
                  "interval": "month",
                  "interval_count": 1,
                  "livemode": false,
                  "nickname": "Pro",
                  "product": "prod_test123456",
                  "trial_period_days": null,
                  "usage_type": "licensed"
                },
                "price": {
                  "id": "price_1S2sxj2MDBrgqcCAmE4CiNVz",
                  "object": "price",
                  "active": true,
                  "billing_scheme": "per_unit",
                  "created": ' . time() . ',
                  "currency": "usd",
                  "livemode": false,
                  "lookup_key": null,
                  "nickname": "Pro",
                  "product": "prod_test123456",
                  "recurring": {
                    "aggregate_usage": null,
                    "interval": "month",
                    "interval_count": 1,
                    "trial_period_days": null,
                    "usage_type": "licensed"
                  },
                  "tax_behavior": "unspecified",
                  "tiers_mode": null,
                  "transform_quantity": null,
                  "type": "recurring",
                  "unit_amount": 4900,
                  "unit_amount_decimal": "4900"
                },
                "quantity": 1,
                "subscription": "sub_test123456",
                "tax_rates": []
              }
            ],
            "has_more": false,
            "total_count": 1,
            "url": "/v1/subscription_items?subscription=sub_test123456"
          },
          "latest_invoice": "in_test123456",
          "livemode": false,
          "metadata": {},
          "next_pending_invoice_item_invoice": null,
          "pause_collection": null,
          "payment_settings": {
            "payment_method_options": null,
            "payment_method_types": null,
            "save_default_payment_method": "off"
          },
          "pending_invoice_item_interval": null,
          "pending_setup_intent": null,
          "pending_update": null,
          "plan": {
            "id": "price_1S2sxj2MDBrgqcCAmE4CiNVz",
            "object": "plan",
            "active": true,
            "amount": 4900,
            "amount_decimal": "4900",
            "billing_scheme": "per_unit",
            "created": ' . time() . ',
            "currency": "usd",
            "interval": "month",
            "interval_count": 1,
            "livemode": false,
            "nickname": "Pro",
            "product": "prod_test123456",
            "trial_period_days": null,
            "usage_type": "licensed"
          },
          "quantity": 1,
          "schedule": null,
          "start_date": ' . time() . ',
          "status": "active",
          "test_clock": null,
          "transfer_data": null,
          "trial_end": null,
          "trial_start": null
        },
        "previous_attributes": {
          "plan": {
            "id": "price_1S2sxC2MDBrgqcCAPtRcfmLE",
            "nickname": "Basic"
          },
          "items": {
            "data": [
              {
                "plan": {
                  "id": "price_1S2sxC2MDBrgqcCAPtRcfmLE",
                  "nickname": "Basic"
                },
                "price": {
                  "id": "price_1S2sxC2MDBrgqcCAPtRcfmLE",
                  "nickname": "Basic"
                }
              }
            ]
          }
        }
      },
      "livemode": false,
      "pending_webhooks": 1,
      "request": {
        "id": "req_test123456",
        "idempotency_key": "test-idempotency-key"
      },
      "type": "customer.subscription.updated"
    }';
    
    // Create a mock request with the payload
    $mockRequest = Request::create(
        '/api/stripe/webhook',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $payload
    );
    
    // Get a Stripe controller instance
    $controller = app(StripeController::class);
    
    // Two options to test:
    
    echo "\n=== Option 1: Monkey patch the Webhook::constructEvent method ===\n";
    file_put_contents(__DIR__.'/webhook_signature_test.log', "\n=== Option 1: Monkey patch the Webhook::constructEvent method ===\n", FILE_APPEND);
    
    // Create a temporary modified version of the controller's handleWebhook method
    $modifiedCode = file_get_contents(app_path('Http/Controllers/StripeController.php'));
    
    // Replace the normal Webhook::constructEvent with a version that skips signature verification
    $eventData = json_decode($payload, true);
    $bypassCode = "
        try {
            // BYPASS SIGNATURE VERIFICATION FOR TESTING
            Log::info('Bypassing Stripe signature verification for testing');
            // Create event object directly without verification
            \$event = new \\Stripe\\Event(json_decode(\$payload, true));
        } catch (\\Exception \$e) {
            Log::error('Error creating mock event: ' . \$e->getMessage());
            return response()->json(['error' => 'Error creating mock event'], 400);
        }
    ";
    
    // Generate test PHP file with modified method
    $testControllerPath = __DIR__.'/TestStripeController.php';
    $modifiedCode = str_replace(
        "try {\n            \$event = \Stripe\Webhook::constructEvent(\n                \$payload, \$sigHeader, \$endpointSecret\n            );", 
        $bypassCode, 
        $modifiedCode
    );
    
    file_put_contents($testControllerPath, $modifiedCode);
    echo "Created test controller at $testControllerPath\n";
    file_put_contents(__DIR__.'/webhook_signature_test.log', "Created test controller at $testControllerPath\n", FILE_APPEND);
    
    echo "\n=== Option 2: Create a direct function that simulates webhook handling ===\n";
    file_put_contents(__DIR__.'/webhook_signature_test.log', "\n=== Option 2: Create a direct function that simulates webhook handling ===\n", FILE_APPEND);
    
    // Extract the important event handling code from the controller
    function processStripeEvent($eventType, $eventData) {
        $subscriptionService = app(\App\Services\SubscriptionService::class);
        
        echo "Processing event type: $eventType\n";
        file_put_contents(__DIR__.'/webhook_signature_test.log', "Processing event type: $eventType\n", FILE_APPEND);
        
        if ($eventType === 'customer.subscription.updated') {
            $subscription = $eventData['data']['object'];
            $planId = null;
            
            echo "Processing subscription update for: {$subscription['id']}\n";
            file_put_contents(__DIR__.'/webhook_signature_test.log', "Processing subscription update for: {$subscription['id']}\n", FILE_APPEND);
            
            // Find matching plan
            if (isset($subscription['items']['data'][0]['plan']['id'])) {
                $stripePlanId = $subscription['items']['data'][0]['plan']['id'];
                echo "Subscription has Stripe Plan ID: $stripePlanId\n";
                file_put_contents(__DIR__.'/webhook_signature_test.log', "Subscription has Stripe Plan ID: $stripePlanId\n", FILE_APPEND);
                
                // Find plan by stripe_price_id
                $plan = \DB::table('plans')->where('stripe_price_id', $stripePlanId)->first();
                if ($plan) {
                    $planId = $plan->id;
                    echo "Matched to Plan ID: $planId\n";
                    file_put_contents(__DIR__.'/webhook_signature_test.log', "Matched to Plan ID: $planId\n", FILE_APPEND);
                } else {
                    echo "No exact match found for Stripe Plan ID. Looking for partial matches...\n";
                    file_put_contents(__DIR__.'/webhook_signature_test.log', "No exact match found for Stripe Plan ID. Looking for partial matches...\n", FILE_APPEND);
                    
                    // Try partial matching
                    $plans = \DB::table('plans')->get();
                    foreach ($plans as $p) {
                        if (!empty($p->stripe_price_id) && stripos($stripePlanId, $p->stripe_price_id) !== false) {
                            $planId = $p->id;
                            echo "Found partial match for Plan ID: $planId\n";
                            file_put_contents(__DIR__.'/webhook_signature_test.log', "Found partial match for Plan ID: $planId\n", FILE_APPEND);
                            break;
                        }
                    }
                }
            }
            
            // Extract subscription metadata
            $stripeSubId = $subscription['id'];
            $status = $subscription['status'];
            $nextBillingDate = isset($subscription['current_period_end']) 
                ? date('Y-m-d H:i:s', $subscription['current_period_end']) 
                : null;
            $cancelAt = isset($subscription['cancel_at']) 
                ? date('Y-m-d H:i:s', $subscription['cancel_at']) 
                : null;
                
            echo "Calling handleSubscriptionUpdated with:\n";
            echo "- Stripe Subscription ID: $stripeSubId\n";
            echo "- Status: $status\n";
            echo "- Next Billing Date: $nextBillingDate\n";
            echo "- Cancel At: " . ($cancelAt ?? 'null') . "\n";
            echo "- Plan ID: " . ($planId ?? 'null') . "\n";
            
            file_put_contents(__DIR__.'/webhook_signature_test.log', "Calling handleSubscriptionUpdated with:\n", FILE_APPEND);
            file_put_contents(__DIR__.'/webhook_signature_test.log', "- Stripe Subscription ID: $stripeSubId\n", FILE_APPEND);
            file_put_contents(__DIR__.'/webhook_signature_test.log', "- Status: $status\n", FILE_APPEND);
            file_put_contents(__DIR__.'/webhook_signature_test.log', "- Next Billing Date: $nextBillingDate\n", FILE_APPEND);
            file_put_contents(__DIR__.'/webhook_signature_test.log', "- Cancel At: " . ($cancelAt ?? 'null') . "\n", FILE_APPEND);
            file_put_contents(__DIR__.'/webhook_signature_test.log', "- Plan ID: " . ($planId ?? 'null') . "\n", FILE_APPEND);
            
            // Create a fake subscription in database if needed for testing
            $existingSubscription = \DB::table('subscriptions')->where('stripe_subscription_id', $stripeSubId)->first();
            if (!$existingSubscription) {
                echo "No subscription found with stripe_subscription_id: $stripeSubId\n";
                echo "Creating a test subscription entry for demonstration purposes...\n";
                file_put_contents(__DIR__.'/webhook_signature_test.log', "No subscription found with stripe_subscription_id: $stripeSubId\n", FILE_APPEND);
                file_put_contents(__DIR__.'/webhook_signature_test.log', "Creating a test subscription entry for demonstration purposes...\n", FILE_APPEND);
                
                // Create a test subscription
                $userId = \DB::table('users')->value('id');
                $initialPlanId = \DB::table('plans')->where('name', 'Free')->value('id') ?? 1;
                
                \DB::table('subscriptions')->insert([
                    'user_id' => $userId,
                    'plan_id' => $initialPlanId,
                    'stripe_subscription_id' => $stripeSubId,
                    'status' => 'active',
                    'start_date' => now(),
                    'next_billing_date' => $nextBillingDate,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                
                echo "Created test subscription with:\n";
                echo "- User ID: $userId\n";
                echo "- Initial Plan ID: $initialPlanId\n";
                echo "- Stripe Subscription ID: $stripeSubId\n";
                file_put_contents(__DIR__.'/webhook_signature_test.log', "Created test subscription with:\n", FILE_APPEND);
                file_put_contents(__DIR__.'/webhook_signature_test.log', "- User ID: $userId\n", FILE_APPEND);
                file_put_contents(__DIR__.'/webhook_signature_test.log', "- Initial Plan ID: $initialPlanId\n", FILE_APPEND);
                file_put_contents(__DIR__.'/webhook_signature_test.log', "- Stripe Subscription ID: $stripeSubId\n", FILE_APPEND);
            }
            
            // Call the service method
            $result = $subscriptionService->handleSubscriptionUpdated(
                $stripeSubId,
                $status,
                $nextBillingDate,
                $cancelAt,
                $planId
            );
            
            echo "handleSubscriptionUpdated result: " . ($result ? "SUCCESS" : "FAILED") . "\n";
            file_put_contents(__DIR__.'/webhook_signature_test.log', "handleSubscriptionUpdated result: " . ($result ? "SUCCESS" : "FAILED") . "\n", FILE_APPEND);
            
            // Verify the update
            $updatedSubscription = \DB::table('subscriptions')->where('stripe_subscription_id', $stripeSubId)->first();
            if ($updatedSubscription) {
                echo "Subscription after update:\n";
                echo "- Plan ID: {$updatedSubscription->plan_id}\n";
                echo "- Status: {$updatedSubscription->status}\n";
                echo "- Next Billing Date: {$updatedSubscription->next_billing_date}\n";
                file_put_contents(__DIR__.'/webhook_signature_test.log', "Subscription after update:\n", FILE_APPEND);
                file_put_contents(__DIR__.'/webhook_signature_test.log', "- Plan ID: {$updatedSubscription->plan_id}\n", FILE_APPEND);
                file_put_contents(__DIR__.'/webhook_signature_test.log', "- Status: {$updatedSubscription->status}\n", FILE_APPEND);
                file_put_contents(__DIR__.'/webhook_signature_test.log', "- Next Billing Date: {$updatedSubscription->next_billing_date}\n", FILE_APPEND);
                
                if ($planId && $updatedSubscription->plan_id == $planId) {
                    echo "✅ Plan ID updated successfully!\n";
                    file_put_contents(__DIR__.'/webhook_signature_test.log', "✅ Plan ID updated successfully!\n", FILE_APPEND);
                } else if ($planId) {
                    echo "❌ Plan ID NOT updated! Expected $planId, got {$updatedSubscription->plan_id}\n";
                    file_put_contents(__DIR__.'/webhook_signature_test.log', "❌ Plan ID NOT updated! Expected $planId, got {$updatedSubscription->plan_id}\n", FILE_APPEND);
                }
            } else {
                echo "❌ Subscription not found after update!\n";
                file_put_contents(__DIR__.'/webhook_signature_test.log', "❌ Subscription not found after update!\n", FILE_APPEND);
            }
            
            return $result;
        }
        
        echo "Event type not handled: $eventType\n";
        file_put_contents(__DIR__.'/webhook_signature_test.log', "Event type not handled: $eventType\n", FILE_APPEND);
        return false;
    }
    
    // Execute our test function
    $eventData = json_decode($payload, true);
    $result = processStripeEvent($eventData['type'], $eventData);
    
    // Instructions for fixing the webhook signature issue
    echo "\n=== How to Fix the Webhook Signature Issue ===\n";
    echo "1. Verify that your STRIPE_WEBHOOK_SECRET in .env is correct\n";
    echo "2. Get the webhook secret from Stripe CLI when you run 'stripe listen'\n";
    echo "3. Update your .env file with the new webhook secret\n";
    echo "4. Restart your Laravel application\n";
    echo "5. Test with the Stripe CLI command: stripe trigger customer.subscription.updated\n";
    
    file_put_contents(__DIR__.'/webhook_signature_test.log', "\n=== How to Fix the Webhook Signature Issue ===\n", FILE_APPEND);
    file_put_contents(__DIR__.'/webhook_signature_test.log', "1. Verify that your STRIPE_WEBHOOK_SECRET in .env is correct\n", FILE_APPEND);
    file_put_contents(__DIR__.'/webhook_signature_test.log', "2. Get the webhook secret from Stripe CLI when you run 'stripe listen'\n", FILE_APPEND);
    file_put_contents(__DIR__.'/webhook_signature_test.log', "3. Update your .env file with the new webhook secret\n", FILE_APPEND);
    file_put_contents(__DIR__.'/webhook_signature_test.log', "4. Restart your Laravel application\n", FILE_APPEND);
    file_put_contents(__DIR__.'/webhook_signature_test.log', "5. Test with the Stripe CLI command: stripe trigger customer.subscription.updated\n", FILE_APPEND);
    
    echo "\nTest completed! Check webhook_signature_test.log for details.\n";
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    file_put_contents(__DIR__.'/webhook_signature_test.log', "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    file_put_contents(__DIR__.'/webhook_signature_test.log', "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
}

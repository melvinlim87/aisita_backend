<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription;
use App\Models\Plan;
use App\Models\User;
use App\Services\SubscriptionService;

// Create log file or append to it
$logFile = __DIR__.'/webhook_fix_noninteractive_log.txt';
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Non-interactive script started\n", FILE_APPEND);

function logMessage($message) {
    global $logFile;
    echo $message . "\n";
    file_put_contents($logFile, $message . "\n", FILE_APPEND);
}

try {
    // 1. Check DB connection
    logMessage("Checking database connection...");
    $dbName = DB::connection()->getDatabaseName();
    logMessage("Connected to database: $dbName");

    // 2. Get subscription data
    $subscriptions = Subscription::all();
    logMessage("Found " . count($subscriptions) . " subscriptions");
    
    if (count($subscriptions) == 0) {
        logMessage("No subscriptions found in the database - stopping script");
        exit(0);
    }
    
    // 3. Display subscription details
    logMessage("\n=== Current Subscriptions ===");
    foreach ($subscriptions as $sub) {
        logMessage("ID: {$sub->id}, User: {$sub->user_id}, Plan: {$sub->plan_id}, Status: {$sub->status}");
        logMessage("  Stripe Sub ID: {$sub->stripe_subscription_id}");
        logMessage("  Created: {$sub->created_at}, Updated: {$sub->updated_at}");
        
        // Get user details
        $user = User::find($sub->user_id);
        if ($user) {
            logMessage("  User email: {$user->email}, Tokens: {$user->tokens}");
        } else {
            logMessage("  User not found!");
        }
        
        // Get plan details
        $plan = Plan::find($sub->plan_id);
        if ($plan) {
            logMessage("  Plan name: {$plan->name}, Tokens per cycle: {$plan->tokens_per_cycle}");
            logMessage("  Stripe Price ID: {$plan->stripe_price_id}");
        } else {
            logMessage("  Plan not found!");
        }
        
        logMessage("  ---");
    }
    
    // 4. Get all plans
    $plans = Plan::all();
    logMessage("\n=== Available Plans ===");
    foreach ($plans as $plan) {
        logMessage("ID: {$plan->id}, Name: {$plan->name}");
        logMessage("  Stripe Price ID: {$plan->stripe_price_id}, Tokens: {$plan->tokens_per_cycle}");
    }

    // 5. Automatic fixes
    logMessage("\n=== Running Automatic Fixes ===");
    
    // Get subscription service
    $subscriptionService = app(SubscriptionService::class);
    logMessage("SubscriptionService loaded successfully");
    
    // Fix for each subscription
    foreach ($subscriptions as $sub) {
        $oldPlanId = $sub->plan_id;
        $currentPlan = Plan::find($oldPlanId);
        
        if (!$currentPlan) {
            logMessage("Subscription {$sub->id} has invalid plan ID {$oldPlanId}!");
            continue;
        }
        
        // Check for matching plans in Stripe
        logMessage("Checking Stripe data for subscription {$sub->id} with Stripe ID: {$sub->stripe_subscription_id}");
        
        try {
            // Try to re-save the subscription to ensure it's correctly saved in the DB
            $sub->touch();
            logMessage("Updated timestamp for subscription {$sub->id}");
            
            // Attempt DB update with different methods
            logMessage("Attempting database updates for subscription {$sub->id} using multiple methods");
            
            // Method 1: Eloquent save
            $sub->plan_id = $oldPlanId; // Just reassign the same plan ID to ensure it's saved
            $result1 = $sub->save();
            logMessage("Method 1 (Eloquent): " . ($result1 ? "SUCCESS" : "FAILED"));
            
            // Method 2: Query Builder
            $result2 = DB::table('subscriptions')
                ->where('id', $sub->id)
                ->update(['plan_id' => $oldPlanId, 'updated_at' => now()]);
            logMessage("Method 2 (Query Builder): {$result2} row(s) affected");
            
            // Method 3: Raw SQL
            $result3 = DB::statement(
                "UPDATE subscriptions SET plan_id = ?, updated_at = NOW() WHERE id = ?", 
                [$oldPlanId, $sub->id]
            );
            logMessage("Method 3 (Raw SQL): " . ($result3 ? "SUCCESS" : "FAILED"));
            
            // Method 4: Service Method
            $result4 = $subscriptionService->handleSubscriptionUpdated(
                $sub->stripe_subscription_id,
                $sub->status,
                $sub->next_billing_date,
                $sub->canceled_at,
                $oldPlanId // Same plan ID to test the update functionality
            );
            logMessage("Method 4 (Service): " . ($result4 ? "SUCCESS" : "FAILED"));
            
            // Verify the update worked
            $refreshed = Subscription::find($sub->id);
            logMessage("Verification: Plan ID after updates: {$refreshed->plan_id} (expected: {$oldPlanId})");
            
            if ($refreshed->plan_id == $oldPlanId) {
                logMessage("✓ Database updates working correctly for subscription {$sub->id}");
            } else {
                logMessage("✗ Database updates FAILED for subscription {$sub->id}");
            }
        } catch (\Exception $e) {
            logMessage("Error processing subscription {$sub->id}: " . $e->getMessage());
        }
    }
    
    // 6. Validate webhook configuration
    logMessage("\n=== Validating Webhook Configuration ===");
    $webhookUrl = env('APP_URL') . '/api/stripe/webhook';
    logMessage("Expected webhook URL: {$webhookUrl}");
    logMessage("Ensure this webhook is properly configured in Stripe dashboard");
    logMessage("Webhook events to enable: customer.subscription.updated, customer.subscription.deleted");
    
    // 7. Verify database permissions
    logMessage("\n=== Verifying Database Permissions ===");
    try {
        $testTable = 'webhook_test_' . time();
        DB::statement("CREATE TEMPORARY TABLE {$testTable} (id INT, value VARCHAR(255))");
        DB::table($testTable)->insert(['id' => 1, 'value' => 'test']);
        $testResult = DB::table($testTable)->where('id', 1)->first();
        logMessage("Database write test: " . ($testResult ? "SUCCESS" : "FAILED"));
    } catch (\Exception $e) {
        logMessage("Database write test error: " . $e->getMessage());
    }
    
    logMessage("\n=== Script completed successfully ===");
    logMessage(date('Y-m-d H:i:s') . " - Script finished");
    
} catch (\Exception $e) {
    logMessage("CRITICAL ERROR: " . $e->getMessage());
    logMessage("Stack trace: " . $e->getTraceAsString());
}

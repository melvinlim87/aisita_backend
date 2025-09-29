<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription;
use App\Models\Plan;
use App\Services\SubscriptionService;

// Create log file or append to it
file_put_contents(__DIR__.'/webhook_fix_log.txt', date('Y-m-d H:i:s') . " - Script started\n", FILE_APPEND);

echo "=== Stripe Webhook Fix Script ===\n";

try {
    // 1. Get DB connection status
    echo "Checking database connection... ";
    $dbName = DB::connection()->getDatabaseName();
    echo "Connected to database: $dbName\n";
    file_put_contents(__DIR__.'/webhook_fix_log.txt', "Connected to database: $dbName\n", FILE_APPEND);

    // 2. Check if subscriptions table exists
    echo "Checking subscriptions table... ";
    $tables = DB::select("SHOW TABLES LIKE 'subscriptions'");
    if (count($tables) == 0) {
        echo "Table 'subscriptions' does not exist!\n";
        file_put_contents(__DIR__.'/webhook_fix_log.txt', "Error: Table 'subscriptions' does not exist!\n", FILE_APPEND);
        exit(1);
    }
    echo "Table exists\n";

    // 3. Get subscription data
    $subscriptions = Subscription::all();
    echo "Found " . count($subscriptions) . " subscriptions\n\n";
    file_put_contents(__DIR__.'/webhook_fix_log.txt', "Found " . count($subscriptions) . " subscriptions\n", FILE_APPEND);

    // 4. Display subscriptions
    echo "=== Current Subscriptions ===\n";
    foreach ($subscriptions as $sub) {
        echo "ID: {$sub->id}, User: {$sub->user_id}, Plan: {$sub->plan_id}, Status: {$sub->status}\n";
        echo "  Stripe Sub ID: {$sub->stripe_subscription_id}\n";
        echo "  Updated: {$sub->updated_at}\n";
    }
    
    // 5. Display plans
    $plans = Plan::all();
    echo "\n=== Available Plans ===\n";
    foreach ($plans as $plan) {
        echo "ID: {$plan->id}, Name: {$plan->name}\n";
        echo "  Stripe Price ID: {$plan->stripe_price_id}, Tokens: {$plan->tokens_per_cycle}\n";
    }
    
    // 6. Check if service provider is loaded
    echo "\nChecking SubscriptionService...\n";
    try {
        $subscriptionService = app(SubscriptionService::class);
        echo "SubscriptionService loaded successfully\n";
        file_put_contents(__DIR__.'/webhook_fix_log.txt', "SubscriptionService loaded successfully\n", FILE_APPEND);
    } catch (\Exception $e) {
        echo "Error loading SubscriptionService: " . $e->getMessage() . "\n";
        file_put_contents(__DIR__.'/webhook_fix_log.txt', "Error loading SubscriptionService: " . $e->getMessage() . "\n", FILE_APPEND);
    }
    
    // 7. Interactive fix
    if (count($subscriptions) > 0) {
        echo "\nWould you like to manually update a subscription? (y/n): ";
        $answer = trim(fgets(STDIN));
        
        if (strtolower($answer) === 'y') {
            echo "Select subscription to update (0-" . (count($subscriptions) - 1) . "): ";
            $subIndex = (int)trim(fgets(STDIN));
            
            if ($subIndex >= 0 && $subIndex < count($subscriptions)) {
                $sub = $subscriptions[$subIndex];
                
                echo "Select plan to assign (0-" . (count($plans) - 1) . "): ";
                $planIndex = (int)trim(fgets(STDIN));
                
                if ($planIndex >= 0 && $planIndex < count($plans)) {
                    $plan = $plans[$planIndex];
                    $oldPlanId = $sub->plan_id;
                    
                    // Use multiple update strategies for redundancy
                    echo "\n=== Updating subscription {$sub->id} from plan {$oldPlanId} to plan {$plan->id} ===\n";
                    file_put_contents(__DIR__.'/webhook_fix_log.txt', "Updating subscription {$sub->id} from plan {$oldPlanId} to plan {$plan->id}\n", FILE_APPEND);
                    
                    // Strategy 1: Direct Model Update
                    try {
                        $sub->plan_id = $plan->id;
                        $result1 = $sub->save();
                        echo "Strategy 1 (Model): " . ($result1 ? "SUCCESS" : "FAILED") . "\n";
                        file_put_contents(__DIR__.'/webhook_fix_log.txt', "Strategy 1 (Model): " . ($result1 ? "SUCCESS" : "FAILED") . "\n", FILE_APPEND);
                    } catch (\Exception $e) {
                        echo "Strategy 1 Error: " . $e->getMessage() . "\n";
                        file_put_contents(__DIR__.'/webhook_fix_log.txt', "Strategy 1 Error: " . $e->getMessage() . "\n", FILE_APPEND);
                    }
                    
                    // Strategy 2: Query Builder
                    try {
                        $result2 = DB::table('subscriptions')
                            ->where('id', $sub->id)
                            ->update(['plan_id' => $plan->id]);
                        echo "Strategy 2 (Query Builder): {$result2} row(s) affected\n";
                        file_put_contents(__DIR__.'/webhook_fix_log.txt', "Strategy 2 (Query Builder): {$result2} row(s) affected\n", FILE_APPEND);
                    } catch (\Exception $e) {
                        echo "Strategy 2 Error: " . $e->getMessage() . "\n";
                        file_put_contents(__DIR__.'/webhook_fix_log.txt', "Strategy 2 Error: " . $e->getMessage() . "\n", FILE_APPEND);
                    }
                    
                    // Strategy 3: Raw SQL
                    try {
                        $result3 = DB::statement("UPDATE subscriptions SET plan_id = ? WHERE id = ?", [$plan->id, $sub->id]);
                        echo "Strategy 3 (Raw SQL): " . ($result3 ? "SUCCESS" : "FAILED") . "\n";
                        file_put_contents(__DIR__.'/webhook_fix_log.txt', "Strategy 3 (Raw SQL): " . ($result3 ? "SUCCESS" : "FAILED") . "\n", FILE_APPEND);
                    } catch (\Exception $e) {
                        echo "Strategy 3 Error: " . $e->getMessage() . "\n";
                        file_put_contents(__DIR__.'/webhook_fix_log.txt', "Strategy 3 Error: " . $e->getMessage() . "\n", FILE_APPEND);
                    }
                    
                    // Strategy 4: Service method
                    if (isset($subscriptionService)) {
                        try {
                            $result4 = $subscriptionService->handleSubscriptionUpdated(
                                $sub->stripe_subscription_id,
                                $sub->status,
                                $sub->next_billing_date,
                                $sub->canceled_at,
                                $plan->id
                            );
                            echo "Strategy 4 (Service): " . ($result4 ? "SUCCESS" : "FAILED") . "\n";
                            file_put_contents(__DIR__.'/webhook_fix_log.txt', "Strategy 4 (Service): " . ($result4 ? "SUCCESS" : "FAILED") . "\n", FILE_APPEND);
                        } catch (\Exception $e) {
                            echo "Strategy 4 Error: " . $e->getMessage() . "\n";
                            file_put_contents(__DIR__.'/webhook_fix_log.txt', "Strategy 4 Error: " . $e->getMessage() . "\n", FILE_APPEND);
                        }
                    }
                    
                    // Verify the update
                    $updated = Subscription::find($sub->id);
                    echo "\nVerification: Plan ID is now {$updated->plan_id} (expected: {$plan->id})\n";
                    file_put_contents(__DIR__.'/webhook_fix_log.txt', "Verification: Plan ID is now {$updated->plan_id} (expected: {$plan->id})\n", FILE_APPEND);
                    
                    echo "Update " . ($updated->plan_id == $plan->id ? "SUCCEEDED" : "FAILED") . "\n";
                    file_put_contents(__DIR__.'/webhook_fix_log.txt', "Update " . ($updated->plan_id == $plan->id ? "SUCCEEDED" : "FAILED") . "\n", FILE_APPEND);
                } else {
                    echo "Invalid plan selection.\n";
                }
            } else {
                echo "Invalid subscription selection.\n";
            }
        }
    }
    
    // 8. Check for any global write-protection
    echo "\n=== Checking Database Write Permissions ===\n";
    try {
        $testTable = 'webhook_test_' . time();
        DB::statement("CREATE TEMPORARY TABLE {$testTable} (id INT, value VARCHAR(255))");
        DB::table($testTable)->insert(['id' => 1, 'value' => 'test']);
        $testResult = DB::table($testTable)->where('id', 1)->first();
        echo "Database write test: " . ($testResult ? "SUCCESS" : "FAILED") . "\n";
        file_put_contents(__DIR__.'/webhook_fix_log.txt', "Database write test: " . ($testResult ? "SUCCESS" : "FAILED") . "\n", FILE_APPEND);
    } catch (\Exception $e) {
        echo "Database write test error: " . $e->getMessage() . "\n";
        file_put_contents(__DIR__.'/webhook_fix_log.txt', "Database write test error: " . $e->getMessage() . "\n", FILE_APPEND);
    }
    
    echo "\n=== Script completed ===\n";
    file_put_contents(__DIR__.'/webhook_fix_log.txt', date('Y-m-d H:i:s') . " - Script completed\n", FILE_APPEND);

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    file_put_contents(__DIR__.'/webhook_fix_log.txt', "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    file_put_contents(__DIR__.'/webhook_fix_log.txt', "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
}

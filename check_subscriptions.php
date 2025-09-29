<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Checking Database Connection ===\n";
try {
    if (\DB::connection()->getPdo()) {
        echo "Connected successfully to database: " . \DB::connection()->getDatabaseName() . "\n\n";
    }
} catch (\Exception $e) {
    echo "Connection error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== Current Subscriptions ===\n";
$subscriptions = \DB::table('subscriptions')
    ->select(['id', 'user_id', 'plan_id', 'stripe_subscription_id', 'status', 'created_at', 'updated_at'])
    ->get();

foreach ($subscriptions as $sub) {
    echo "ID: {$sub->id}, User: {$sub->user_id}, Plan: {$sub->plan_id}, Stripe Sub: {$sub->stripe_subscription_id}, Status: {$sub->status}\n";
    echo "  Created: {$sub->created_at}, Updated: {$sub->updated_at}\n";
}
echo "\n";

echo "=== Plans ===\n";
$plans = \DB::table('plans')
    ->select(['id', 'name', 'stripe_price_id', 'tokens_per_cycle'])
    ->get();

foreach ($plans as $plan) {
    echo "ID: {$plan->id}, Name: {$plan->name}, Stripe Price ID: {$plan->stripe_price_id}, Tokens: {$plan->tokens_per_cycle}\n";
}
echo "\n";

echo "=== Testing Direct Update ===\n";
try {
    if (count($subscriptions) > 0) {
        $subId = $subscriptions[0]->id;
        $planIds = $plans->pluck('id')->toArray();
        
        // Find a plan that's different from current one
        foreach ($planIds as $planId) {
            if ($planId != $subscriptions[0]->plan_id) {
                echo "Attempting to update subscription {$subId} from plan {$subscriptions[0]->plan_id} to plan {$planId}\n";
                
                $result1 = \DB::table('subscriptions')
                    ->where('id', $subId)
                    ->update(['plan_id' => $planId]);
                    
                echo "Query builder result: " . ($result1 ? 'SUCCESS' : 'FAILED') . " ({$result1} rows affected)\n";
                
                // Check if it worked
                $after = \DB::table('subscriptions')->where('id', $subId)->first();
                echo "New plan_id: {$after->plan_id} (expected: {$planId})\n";
                
                // Try direct SQL
                $sql = "UPDATE subscriptions SET plan_id = ? WHERE id = ?";
                $result2 = \DB::statement($sql, [$subscriptions[0]->plan_id, $subId]);  // Change back
                echo "Direct SQL result: " . ($result2 ? 'SUCCESS' : 'FAILED') . "\n";
                
                break;
            }
        }
    } else {
        echo "No subscriptions found to test updates.\n";
    }
} catch (\Exception $e) {
    echo "Update test error: " . $e->getMessage() . "\n";
}

echo "\nDone.\n";

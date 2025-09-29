<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Subscription;
use App\Models\Plan;

echo "=== Force Subscription Update Script ===\n";

// 1. Get all active subscriptions
$subscriptions = Subscription::where('status', 'active')->get();
echo "Found " . count($subscriptions) . " active subscriptions.\n";

if (count($subscriptions) == 0) {
    echo "No active subscriptions found. Checking all subscriptions regardless of status...\n";
    $subscriptions = Subscription::all();
    echo "Found " . count($subscriptions) . " total subscriptions.\n";
}

if (count($subscriptions) == 0) {
    echo "No subscriptions found in the database.\n";
    exit(1);
}

// 2. Show subscription details
foreach ($subscriptions as $index => $sub) {
    echo "\n[$index] Subscription ID: {$sub->id}\n";
    echo "  User ID: {$sub->user_id}\n";
    echo "  Plan ID: {$sub->plan_id}\n";
    echo "  Stripe Subscription ID: {$sub->stripe_subscription_id}\n";
    echo "  Status: {$sub->status}\n";
    echo "  Created: {$sub->created_at}\n";
    echo "  Updated: {$sub->updated_at}\n";
}

// 3. Get available plans
$plans = Plan::all();
echo "\n=== Available Plans ===\n";
foreach ($plans as $index => $plan) {
    echo "[$index] Plan ID: {$plan->id}, Name: {$plan->name}, Stripe ID: {$plan->stripe_price_id}, Tokens: {$plan->tokens_per_cycle}\n";
}

// 4. Select which subscription to update
echo "\nSelect subscription to update (0-" . (count($subscriptions) - 1) . "): ";
$subIndex = trim(fgets(STDIN));
if (!is_numeric($subIndex) || $subIndex < 0 || $subIndex >= count($subscriptions)) {
    echo "Invalid selection.\n";
    exit(1);
}

// 5. Select which plan to assign
echo "Select new plan (0-" . (count($plans) - 1) . "): ";
$planIndex = trim(fgets(STDIN));
if (!is_numeric($planIndex) || $planIndex < 0 || $planIndex >= count($plans)) {
    echo "Invalid selection.\n";
    exit(1);
}

$subscription = $subscriptions[$subIndex];
$plan = $plans[$planIndex];

// 6. Confirm update
echo "\nYou are about to update:\n";
echo "Subscription {$subscription->id} from Plan {$subscription->plan_id} to Plan {$plan->id}\n";
echo "Continue? (y/n): ";
$confirm = trim(fgets(STDIN));
if (strtolower($confirm) !== 'y') {
    echo "Operation cancelled.\n";
    exit(0);
}

// 7. Perform update using multiple methods
echo "\n=== Attempting Direct Update ===\n";

echo "Updating with Eloquent: ";
try {
    $oldPlanId = $subscription->plan_id;
    $subscription->plan_id = $plan->id;
    $success = $subscription->save();
    echo ($success ? "SUCCESS" : "FAILED") . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "Updating with Query Builder: ";
try {
    $affected = DB::table('subscriptions')
        ->where('id', $subscription->id)
        ->update(['plan_id' => $plan->id]);
    echo "{$affected} row(s) affected\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "Updating with Raw SQL: ";
try {
    $success = DB::statement(
        "UPDATE subscriptions SET plan_id = ? WHERE id = ?",
        [$plan->id, $subscription->id]
    );
    echo ($success ? "SUCCESS" : "FAILED") . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// 8. Verify the update
$refreshed = Subscription::find($subscription->id);
echo "\n=== Update Verification ===\n";
echo "Original plan_id: {$oldPlanId}\n";
echo "Expected new plan_id: {$plan->id}\n";
echo "Actual new plan_id: {$refreshed->plan_id}\n";

echo "\nUpdate " . ($refreshed->plan_id == $plan->id ? "SUCCEEDED" : "FAILED") . "\n";

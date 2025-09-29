<?php
/**
 * Manual test script for token subscription enforcement
 * 
 * To run this test, use:
 * php artisan tinker tests/manual/token_subscription_test.php
 */

use App\Models\User;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\TokenController;

// Helper to print test result
function test_result($name, $passed, $response_content = null) {
    echo "\n\033[1m" . $name . "\033[0m: ";
    if ($passed) {
        echo "\033[32m✓ PASSED\033[0m";
    } else {
        echo "\033[31m✗ FAILED\033[0m";
        if ($response_content) {
            echo " - " . $response_content;
        }
    }
    echo "\n";
}

// Clean up any test users from previous runs
$testEmails = [
    'telegram_full@test.com',
    'telegram_used@test.com',
    'telegram_subscription@test.com',
    'whatsapp_used@test.com',
    'standard@test.com',
    'firebase@test.com'
];

foreach ($testEmails as $email) {
    $existingUser = User::where('email', $email)->first();
    if ($existingUser) {
        $existingUser->delete();
    }
}

echo "\n\033[1m=== Testing Token Subscription Enforcement ===\033[0m\n";

// Get a TokenController instance
$controller = app()->make(TokenController::class);

// Scenario 1: Telegram user with full free tokens (should be allowed to purchase)
$telegramUserFull = new User([
    'name' => 'Telegram Test User (Full)',
    'email' => 'telegram_full@test.com',
    'telegram_id' => '123456789',
    'telegram_username' => 'test_user_full',
    'password' => Hash::make('password'),
    'subscription_token' => 4000, // Full tokens
    'addons_token' => 0,
    'role_id' => 1
]);
$telegramUserFull->save();

$request = new Request();
$request->merge(['package_id' => 'micro']);
$request->setUserResolver(function () use ($telegramUserFull) {
    return $telegramUserFull;
});

try {
    $response = $controller->purchaseTokens($request);
    $status = $response->getStatusCode();
    $content = json_decode($response->getContent(), true);
    test_result('Telegram User with Full Tokens', $status === 200 && isset($content['success']) && $content['success'] === true);
} catch (\Exception $e) {
    test_result('Telegram User with Full Tokens', false, $e->getMessage());
}

// Scenario 2: Telegram user with used tokens, no subscription (should be denied)
$telegramUserUsed = new User([
    'name' => 'Telegram Test User (Used)',
    'email' => 'telegram_used@test.com',
    'telegram_id' => '987654321',
    'telegram_username' => 'test_user_used',
    'password' => Hash::make('password'),
    'subscription_token' => 3500, // Some tokens used
    'addons_token' => 0,
    'role_id' => 1
]);
$telegramUserUsed->save();

$request = new Request();
$request->merge(['package_id' => 'micro']);
$request->setUserResolver(function () use ($telegramUserUsed) {
    return $telegramUserUsed;
});

try {
    $response = $controller->purchaseTokens($request);
    $status = $response->getStatusCode();
    $content = json_decode($response->getContent(), true);
    test_result(
        'Telegram User with Used Tokens (No Subscription)', 
        $status === 403 && isset($content['message']) && $content['message'] === 'You must purchase a subscription before buying additional tokens.'
    );
} catch (\Exception $e) {
    test_result('Telegram User with Used Tokens (No Subscription)', false, $e->getMessage());
}

// Scenario 3: Telegram user with used tokens AND subscription (should be allowed)
$telegramUserSubscription = new User([
    'name' => 'Telegram Test User (Subscription)',
    'email' => 'telegram_subscription@test.com',
    'telegram_id' => '123123123',
    'telegram_username' => 'test_user_subscription',
    'password' => Hash::make('password'),
    'subscription_token' => 3500, // Some tokens used
    'addons_token' => 0,
    'role_id' => 1
]);
$telegramUserSubscription->save();

// Create a plan for testing
$plan = Plan::firstOrCreate(
    ['name' => 'Test Plan'],
    [
        'description' => 'Plan for testing',
        'price' => 9.99,
        'interval' => 'month',
        'stripe_price_id' => 'price_test',
        'tokens' => 1000,
        'premium_models_access' => true
    ]
);

// Create active subscription for the user
$subscription = new Subscription([
    'user_id' => $telegramUserSubscription->id,
    'plan_id' => $plan->id,
    'status' => 'active',
    'stripe_subscription_id' => 'sub_test',
    'current_period_start' => now(),
    'current_period_end' => now()->addMonth()
]);
$subscription->save();

// Reset and reload the user to ensure relationship is properly loaded
$telegramUserSubscription = User::find($telegramUserSubscription->id);

$request = new Request();
$request->merge(['package_id' => 'micro']);
$request->setUserResolver(function () use ($telegramUserSubscription) {
    return $telegramUserSubscription;
});

try {
    $response = $controller->purchaseTokens($request);
    $status = $response->getStatusCode();
    $content = json_decode($response->getContent(), true);
    test_result('Telegram User with Used Tokens AND Subscription', $status === 200 && isset($content['success']) && $content['success'] === true);
} catch (\Exception $e) {
    test_result('Telegram User with Used Tokens AND Subscription', false, $e->getMessage());
}

// Scenario 4: WhatsApp user with used tokens (should be denied)
$whatsappUser = new User([
    'name' => 'WhatsApp Test User',
    'email' => 'whatsapp_used@test.com',
    'phone_number' => '+1234567890',
    'whatsapp_verified' => true,
    'password' => Hash::make('password'),
    'subscription_token' => 3500, // Some tokens used
    'addons_token' => 0,
    'role_id' => 1
]);
$whatsappUser->save();

$request = new Request();
$request->merge(['package_id' => 'micro']);
$request->setUserResolver(function () use ($whatsappUser) {
    return $whatsappUser;
});

try {
    $response = $controller->purchaseTokens($request);
    $status = $response->getStatusCode();
    $content = json_decode($response->getContent(), true);
    test_result(
        'WhatsApp User with Used Tokens (No Subscription)', 
        $status === 403 && isset($content['message']) && $content['message'] === 'You must purchase a subscription before buying additional tokens.'
    );
} catch (\Exception $e) {
    test_result('WhatsApp User with Used Tokens (No Subscription)', false, $e->getMessage());
}

// Scenario 5: Standard user (no free tokens required, should be allowed)
$standardUser = new User([
    'name' => 'Standard Test User',
    'email' => 'standard@test.com',
    'password' => Hash::make('password'),
    'subscription_token' => 0,
    'addons_token' => 0,
    'role_id' => 1
]);
$standardUser->save();

$request = new Request();
$request->merge(['package_id' => 'micro']);
$request->setUserResolver(function () use ($standardUser) {
    return $standardUser;
});

try {
    $response = $controller->purchaseTokens($request);
    $status = $response->getStatusCode();
    $content = json_decode($response->getContent(), true);
    test_result('Standard User (No Subscription Needed)', $status === 200 && isset($content['success']) && $content['success'] === true);
} catch (\Exception $e) {
    test_result('Standard User (No Subscription Needed)', false, $e->getMessage());
}

// Scenario 6: Firebase user (no free tokens required, should be allowed)
$firebaseUser = new User([
    'name' => 'Firebase Test User',
    'email' => 'firebase@test.com',
    'firebase_uid' => 'firebase_test_123',
    'password' => Hash::make('password'),
    'subscription_token' => 0,
    'addons_token' => 0,
    'role_id' => 1
]);
$firebaseUser->save();

$request = new Request();
$request->merge(['package_id' => 'micro']);
$request->setUserResolver(function () use ($firebaseUser) {
    return $firebaseUser;
});

try {
    $response = $controller->purchaseTokens($request);
    $status = $response->getStatusCode();
    $content = json_decode($response->getContent(), true);
    test_result('Firebase User (No Subscription Needed)', $status === 200 && isset($content['success']) && $content['success'] === true);
} catch (\Exception $e) {
    test_result('Firebase User (No Subscription Needed)', false, $e->getMessage());
}

echo "\n\033[1m=== Test Complete ===\033[0m\n\n";

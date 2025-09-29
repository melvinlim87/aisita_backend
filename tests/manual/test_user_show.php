<?php
/**
 * Manual test for UserController::show method
 * Tests retrieving comprehensive user data with all relationships
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Illuminate\Support\Facades\Http;

// Configuration
$base_url = 'http://localhost:8000/api'; // Update with your actual API URL
$user_id = 1; // Update with a valid user ID to test
$admin_token = 'YOUR_ADMIN_TOKEN_HERE'; // You need an admin token for authentication

// Make the API call to get comprehensive user data
$response = Http::withHeaders([
    'Authorization' => 'Bearer ' . $admin_token,
    'Accept' => 'application/json',
])->get($base_url . '/users/' . $user_id);

// Process and display the response
if ($response->successful()) {
    $userData = $response->json()['data'];
    
    // Display basic user info
    echo "User ID: {$userData['id']}\n";
    echo "Name: {$userData['name']}\n";
    echo "Email: {$userData['email']}\n";
    echo "Role: {$userData['role']['name']}\n";
    echo "\n";
    
    // Display subscription info
    if (!empty($userData['subscriptions'])) {
        echo "== Subscriptions ==\n";
        foreach ($userData['subscriptions'] as $subscription) {
            echo "Status: {$subscription['status']}\n";
            echo "Plan: {$subscription['plan']['name']}\n";
            echo "Tokens per cycle: {$subscription['plan']['tokens_per_cycle']}\n";
            echo "Started: {$subscription['created_at']}\n";
            echo "\n";
        }
    }
    
    // Display purchase history
    if (!empty($userData['purchases'])) {
        echo "== Purchase History ==\n";
        foreach ($userData['purchases'] as $purchase) {
            echo "ID: {$purchase['id']}\n";
            echo "Amount: {$purchase['amount']}\n";
            echo "Description: {$purchase['description']}\n";
            echo "Date: {$purchase['created_at']}\n";
            echo "\n";
        }
    }
    
    // Display support tickets
    if (!empty($userData['support_tickets'])) {
        echo "== Support Tickets ==\n";
        foreach ($userData['support_tickets'] as $ticket) {
            echo "ID: {$ticket['id']}\n";
            echo "Subject: {$ticket['subject']}\n";
            echo "Status: {$ticket['status']}\n";
            echo "Created: {$ticket['created_at']}\n";
            echo "\n";
        }
    }
    
    // Display referrals
    if (!empty($userData['referrals'])) {
        echo "== Referrals ==\n";
        foreach ($userData['referrals'] as $referral) {
            echo "ID: {$referral['id']}\n";
            echo "User ID: {$referral['referred_id']}\n";
            echo "Status: {$referral['status']}\n";
            echo "Date: {$referral['created_at']}\n";
            echo "\n";
        }
    }
    
    // Display raw data for debugging
    echo "== Raw User Data ==\n";
    print_r($userData);
} else {
    echo "Error: " . $response->status() . "\n";
    echo $response->body() . "\n";
}

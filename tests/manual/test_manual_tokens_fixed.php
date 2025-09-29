<?php

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment variables from .env file
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// First get an admin token using the admin user
// Create the admin API token
$admin_email = 'admin@example.com'; // Change this to an actual admin email in your database
$admin_password = 'password'; // Change this to the actual admin password

echo "Getting admin token...\n";

// Get admin token
$admin_token_response = callApi('POST', 'login', [
    'email' => $admin_email,
    'password' => $admin_password,
]);

if (!isset($admin_token_response['token'])) {
    echo "Error getting admin token: " . json_encode($admin_token_response) . "\n";
    exit(1);
}

$admin_token = $admin_token_response['token'];
echo "Admin token: " . $admin_token . "\n\n";

// User ID to add tokens to (change to an actual user ID in your database)
$user_id = 1; // Replace with an actual user ID from your database

// Get current token balance
echo "Getting current token balance for user $user_id...\n";
$current_balance = callApi('GET', 'tokens/balance', [], $admin_token);
echo "Current token balance: " . json_encode($current_balance) . "\n\n";

// Test manually adding tokens
echo "Adding tokens to user $user_id...\n";
$result = callApi('POST', 'tokens/manually-add', [
    'user_id' => $user_id,
    'token_amount' => 1000,
    'token_type' => 'addons_token', // or 'subscription_token'
    'reason' => 'Testing manual token addition with fixed price_id',
], $admin_token);

echo "Result of manually adding tokens:\n";
print_r($result);

echo "\n\nGetting updated token balance...\n";
$new_balance = callApi('GET', 'tokens/balance', [], $admin_token);
echo "New token balance: " . json_encode($new_balance) . "\n\n";

/**
 * Call API helper function
 * 
 * @param string $method HTTP method (GET, POST, PUT, DELETE)
 * @param string $endpoint API endpoint to call
 * @param array $data Data to send in request
 * @param string|null $token Bearer token for authentication
 * @return array Response data
 */
function callApi($method, $endpoint, $data = [], $token = null) {
    $api_base = env('APP_URL') . '/api/';
    
    $url = $api_base . $endpoint;
    
    $curl = curl_init();
    
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    
    // Add headers
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token) {
        $headers[] = "Authorization: Bearer $token";
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    
    // Add request data
    if (!empty($data)) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    
    curl_setopt_array($curl, $options);
    
    $response = curl_exec($curl);
    
    if (curl_errno($curl)) {
        echo 'Curl error: ' . curl_error($curl);
        return ['error' => curl_error($curl)];
    }
    
    curl_close($curl);
    
    return json_decode($response, true);
}

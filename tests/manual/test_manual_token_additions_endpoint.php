<?php

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment variables from .env file
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->load();

// First get an admin token using the admin user
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

// First, let's add some manual tokens to create data
$user_id = 1; // Replace with an actual user ID from your database
$reason = "Testing endpoint - " . date('Y-m-d H:i:s');

echo "Adding test tokens to user $user_id...\n";
$add_result = callApi('POST', 'tokens/manually-add', [
    'user_id' => $user_id,
    'token_amount' => 500,
    'token_type' => 'subscription_token',
    'reason' => $reason,
], $admin_token);

echo "Result of manually adding tokens:\n";
print_r($add_result);

// Test 1: Get all manual token additions (no filters)
echo "\n\nTest 1: Getting all manual token additions...\n";
$all_additions = callApi('GET', 'admin/manual-token-additions', [], $admin_token);
echo "Results count: " . (isset($all_additions['data']['total']) ? $all_additions['data']['total'] : 'unknown') . "\n";
echo "First few results:\n";
if (isset($all_additions['data']['data']) && !empty($all_additions['data']['data'])) {
    foreach (array_slice($all_additions['data']['data'], 0, 3) as $addition) {
        echo "- ID: " . $addition['id'] . ", Amount: " . $addition['token_amount'] . 
             ", Type: " . $addition['token_type'] . ", Reason: " . $addition['reason'] . "\n";
    }
} else {
    echo "No results found\n";
}

// Test 2: Filter by user_id
echo "\n\nTest 2: Filtering by user_id=$user_id...\n";
$user_filtered = callApi('GET', 'admin/manual-token-additions?user_id=' . $user_id, [], $admin_token);
echo "Results count: " . (isset($user_filtered['data']['total']) ? $user_filtered['data']['total'] : 'unknown') . "\n";

// Test 3: Filter by token_type
echo "\n\nTest 3: Filtering by token_type=subscription_token...\n";
$type_filtered = callApi('GET', 'admin/manual-token-additions?token_type=subscription_token', [], $admin_token);
echo "Results count: " . (isset($type_filtered['data']['total']) ? $type_filtered['data']['total'] : 'unknown') . "\n";

// Test 4: Filter by date range
$today = date('Y-m-d');
echo "\n\nTest 4: Filtering by date range (today: $today)...\n";
$date_filtered = callApi('GET', 'admin/manual-token-additions?from_date=' . $today . '&to_date=' . $today, [], $admin_token);
echo "Results count: " . (isset($date_filtered['data']['total']) ? $date_filtered['data']['total'] : 'unknown') . "\n";

// Test 5: Pagination
echo "\n\nTest 5: Testing pagination (page 1, 5 per page)...\n";
$paginated = callApi('GET', 'admin/manual-token-additions?per_page=5&page=1', [], $admin_token);
echo "Results count: " . (isset($paginated['data']['total']) ? $paginated['data']['total'] : 'unknown') . "\n";
echo "Current page: " . (isset($paginated['data']['current_page']) ? $paginated['data']['current_page'] : 'unknown') . "\n";
echo "Per page: " . (isset($paginated['data']['per_page']) ? $paginated['data']['per_page'] : 'unknown') . "\n";

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
    if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
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

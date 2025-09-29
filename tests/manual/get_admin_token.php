<?php
/**
 * Helper script to get an admin token for testing purposes
 * This script logs in as an admin user and returns a valid token
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Initialize Laravel application
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

// Find an admin user or create one if needed
$admin = User::whereHas('role', function ($query) {
    $query->where('name', 'admin')->orWhere('name', 'super_admin');
})->first();

if (!$admin) {
    echo "No admin user found. You should log in through the normal login process with an admin account.\n";
    exit(1);
}

// Create a new token for this admin user
$token = $admin->createToken('admin-test-token', ['*'], now()->addDays(1));

echo "==============================================\n";
echo "Admin User: {$admin->name} (ID: {$admin->id})\n";
echo "Email: {$admin->email}\n";
echo "==============================================\n\n";
echo "TOKEN: {$token->plainTextToken}\n\n";
echo "To use this token in the test script:\n";
echo "1. Copy the token above\n";
echo "2. Replace 'YOUR_ADMIN_TOKEN_HERE' in test_user_show.php with this token\n";
echo "3. Run: php tests/manual/test_user_show.php\n";

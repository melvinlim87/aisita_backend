<?php

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

// Make sure we have the right parameters
if ($argc < 2) {
    echo "Usage: php update_webhook_secret.php <webhook_secret>\n";
    echo "Example: php update_webhook_secret.php whsec_abcdefghijklmnopqrstuvwxyz\n";
    exit(1);
}

$webhookSecret = $argv[1];

// Validate webhook secret format (should start with whsec_)
if (!preg_match('/^whsec_[a-zA-Z0-9]+$/', $webhookSecret)) {
    echo "Error: Invalid webhook secret format. It should start with 'whsec_' followed by alphanumeric characters.\n";
    exit(1);
}

// Path to .env file
$envFile = __DIR__ . '/.env';

if (!file_exists($envFile)) {
    echo "Error: .env file not found at {$envFile}\n";
    exit(1);
}

// Read the current .env file
$envContent = file_get_contents($envFile);

// Check if STRIPE_WEBHOOK_SECRET already exists
if (preg_match('/^STRIPE_WEBHOOK_SECRET=(.*)$/m', $envContent, $matches)) {
    $currentSecret = $matches[1];
    echo "Current webhook secret: " . substr($currentSecret, 0, 5) . "...\n";
    
    // Replace the existing webhook secret
    $envContent = preg_replace(
        '/^STRIPE_WEBHOOK_SECRET=.*$/m',
        "STRIPE_WEBHOOK_SECRET={$webhookSecret}",
        $envContent
    );
    
    echo "Updating existing webhook secret...\n";
} else {
    // Add the webhook secret to the end of the file
    $envContent .= "\nSTRIPE_WEBHOOK_SECRET={$webhookSecret}\n";
    echo "Adding new webhook secret...\n";
}

// Create a backup of the original .env file
$backupFile = __DIR__ . '/.env.backup.' . date('YmdHis');
file_put_contents($backupFile, file_get_contents($envFile));
echo "Created backup of .env file at {$backupFile}\n";

// Write the updated content back to the .env file
if (file_put_contents($envFile, $envContent) !== false) {
    echo "Successfully updated STRIPE_WEBHOOK_SECRET in .env file.\n";
    echo "New webhook secret: " . substr($webhookSecret, 0, 5) . "...\n";
    echo "\nREMEMBER: You need to restart your Laravel application for these changes to take effect.\n";
} else {
    echo "Error: Failed to write to .env file. Check file permissions.\n";
    exit(1);
}

// Verify the update
$updatedEnvContent = file_get_contents($envFile);
if (strpos($updatedEnvContent, "STRIPE_WEBHOOK_SECRET={$webhookSecret}") !== false) {
    echo "Verification: Webhook secret has been properly saved to .env file.\n";
} else {
    echo "Warning: Could not verify webhook secret in .env file. Please check manually.\n";
}

// Instructions for testing
echo "\n======= NEXT STEPS =======\n";
echo "1. Restart your Laravel application\n";
echo "2. Run the following Stripe CLI commands in separate terminals:\n";
echo "   - Start webhook forwarding: stripe listen --forward-to http://localhost:8000/api/stripe/webhook\n";
echo "   - Make sure to use the webhook signing secret shown in the output of the above command\n";
echo "3. Test with: stripe trigger customer.subscription.updated\n";
echo "4. Check your Laravel logs for successful webhook processing\n";

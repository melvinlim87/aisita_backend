<?php
// This is a temporary file with the fix for StripeController.php
// Add these two lines right after retrieving $userId and $priceId:

$mode = $request->input('mode', 'payment'); // Default to payment if not specified
$metadata = $request->input('metadata', []); // Get additional metadata if provided

// Make sure your sessionConfig['mode'] uses the $mode variable

// The problem with "userId must be a string" is fixed in SubscriptionController.initiateSubscription
// by explicitly casting the user ID to string: (string)$user->id
?>

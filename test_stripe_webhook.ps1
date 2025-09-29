#!/usr/bin/env pwsh
# PowerShell script to test Stripe webhooks and Customer Portal

# Configuration - Update these values
$BASE_URL = "http://localhost:8000/api"
$AUTH_TOKEN = "Bearer vNktvHFDu97XvngZ75H2nmlyXy7HNLU0Ddcsw9B347cf7e2e"  # Replace with a valid JWT token for an authenticated user
$PLAN_ID = 2  # Replace with a valid plan ID from your database

# Color codes for better visibility
function Write-ColorOutput($ForegroundColor) {
    $fc = $host.UI.RawUI.ForegroundColor
    $host.UI.RawUI.ForegroundColor = $ForegroundColor
    if ($args) {
        Write-Output $args
    }
    $host.UI.RawUI.ForegroundColor = $fc
}

Write-ColorOutput green "Starting Stripe Webhook Testing Script"
Write-ColorOutput yellow "Make sure your Laravel server is running!"

# 1. Test the Customer Portal endpoint
Write-ColorOutput cyan "`n1. Testing Customer Portal Endpoint:"
try {
    $response = Invoke-RestMethod -Uri "$BASE_URL/subscriptions/customer-portal" -Method GET -Headers @{
        "Authorization" = "Bearer $AUTH_TOKEN"
        "Accept" = "application/json"
    }
    Write-ColorOutput green "Success! Customer Portal URL:"
    Write-Output $response.url
    
    # Open the URL in default browser
    Write-ColorOutput yellow "Opening Customer Portal in browser..."
    Start-Process $response.url
} 
catch {
    Write-ColorOutput red "Error accessing Customer Portal:"
    Write-Output $_.Exception.Response.StatusCode.value__
    Write-Output $_.Exception.Response.StatusDescription
    Write-Output $_.Exception.Message
}

# 2. Test the Plan Change endpoint
Write-ColorOutput cyan "`n2. Testing Plan Change Endpoint:"
try {
    $body = @{
        plan_id = $PLAN_ID
    } | ConvertTo-Json

    $response = Invoke-RestMethod -Uri "$BASE_URL/subscriptions/change-plan" -Method POST -Headers @{
        "Authorization" = "Bearer $AUTH_TOKEN"
        "Content-Type" = "application/json"
        "Accept" = "application/json"
    } -Body $body

    Write-ColorOutput green "Success! Customer Portal URL from Plan Change:"
    Write-Output $response.url
}
catch {
    Write-ColorOutput red "Error changing plan:"
    Write-Output $_.Exception.Response.StatusCode.value__
    Write-Output $_.Exception.Response.StatusDescription
    Write-Output $_.Exception.Message
}

# 3. Instructions for Stripe CLI testing
Write-ColorOutput cyan "`n3. Instructions for Stripe CLI Webhook Testing:"
Write-ColorOutput yellow "Open a new terminal and run the following commands:"
Write-Output "stripe listen --forward-to $BASE_URL/stripe/webhook"

# Add instructions for testing specific webhook events
Write-ColorOutput cyan "`n4. Testing Specific Webhook Events:"
Write-ColorOutput yellow "After starting the webhook listener above, open another terminal and trigger these events:"
Write-Output "# Test subscription updated event - replace with your actual subscription ID"
Write-Output "stripe trigger customer.subscription.updated --add subscription=sub_XYZ"

Write-Output "`n# Test invoice events"
Write-Output "stripe trigger invoice.created"
Write-Output "stripe trigger invoice.finalized"
Write-Output "stripe trigger invoice.paid"
Write-Output "stripe trigger invoiceitem.created"

Write-ColorOutput cyan "`n5. Viewing Logs:"
Write-ColorOutput yellow "To monitor Laravel logs while testing webhooks, run this in another terminal:"
Write-Output "# If using Laravel Sail:"
Write-Output "sail logs -f"
Write-Output "`n# Or if using traditional Laravel:"
Write-Output "tail -f storage/logs/laravel.log"

Write-ColorOutput green "`nTesting Tips:"
Write-Output "1. Check the Laravel logs for detailed debugging information"
Write-Output "2. Look for the 'Before update' and 'After update' messages in logs"
Write-Output "3. If subscription is not found, check the 'Available subscriptions' debug output"
Write-Output "4. Make sure your .env contains the correct STRIPE_WEBHOOK_SECRET"
Write-ColorOutput yellow "Take the webhook signing secret provided by the Stripe CLI and add it to your .env file:"
Write-Output "STRIPE_WEBHOOK_SECRET=whsec_your_signing_secret"

Write-Output "`nIn another terminal, trigger test events with:"
Write-ColorOutput cyan "Test checkout completion (most important for subscription creation):"
Write-Output "stripe trigger checkout.session.completed"

Write-ColorOutput cyan "Test subscription updates:"
Write-Output "stripe trigger customer.subscription.updated"

Write-ColorOutput cyan "Test subscription cancellation:"
Write-Output "stripe trigger customer.subscription.deleted"

Write-ColorOutput yellow "Monitor your Laravel logs in another terminal:"
Write-Output "Get-Content -Path './storage/logs/laravel.log' -Tail 20 -Wait"

Write-ColorOutput green "`nTesting script completed!"

<?php

namespace App\Http\Controllers;

use App\Models\EmailCampaign;
use Illuminate\Http\Request; // Keep for methods not using FormRequests
use App\Http\Requests\StoreEmailCampaignRequest;
use App\Http\Requests\UpdateEmailCampaignRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use App\Mail\CampaignMail; // For logging potential errors

class EmailCampaignController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        // Basic implementation: Paginate results, order by newest
        // Add authorization checks if needed (e.g., only show campaigns created by the user)
        $campaigns = EmailCampaign::where('user_id', Auth::id())
                                ->latest()
                                ->paginate(15);
        return response()->json($campaigns);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreEmailCampaignRequest $request): JsonResponse
    {
        $validatedData = $request->validated();

        try {
            $campaign = EmailCampaign::create(array_merge(
                $validatedData,
                ['user_id' => Auth::id()]
            ));
            return response()->json($campaign, 201);
        } catch (\Exception $e) {
            Log::error('Error creating campaign: ' . $e->getMessage());
            return response()->json(['message' => 'Error creating campaign', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(EmailCampaign $campaign): JsonResponse
    {
        // Add authorization: Ensure the user can view this campaign
        if ($campaign->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        return response()->json($campaign);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateEmailCampaignRequest $request, EmailCampaign $campaign): JsonResponse
    {
        // Add authorization: Ensure the user can update this campaign
        if ($campaign->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Prevent updates if campaign is not in a modifiable state (e.g., 'draft' or 'scheduled')
        if (!in_array($campaign->status, ['draft', 'scheduled'])) {
            return response()->json(['message' => 'Campaign cannot be updated in its current status: ' . $campaign->status], 400);
        }
        
        $validatedData = $request->validated();
        
        // Log incoming data for debugging
        Log::info("Updating campaign {$campaign->id} with data:", [
            'user_ids' => $request->input('user_ids'),
            'all_users' => $request->input('all_users')
        ]);
        
        // Ensure user_ids is properly formatted as JSON
        if ($request->has('user_ids')) {
            // Make sure it's an array
            $userIds = $request->input('user_ids');
            if (!is_array($userIds)) {
                $userIds = [$userIds]; // Convert single value to array
            }
            $validatedData['user_ids'] = $userIds;
        }
        
        // Handle all_users flag
        if ($request->has('all_users')) {
            $validatedData['all_users'] = (bool)$request->input('all_users');
        }

        try {
            $campaign->update($validatedData);
            return response()->json($campaign);
        } catch (\Exception $e) {
            Log::error("Error updating campaign {$campaign->id}: " . $e->getMessage(), [
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
                'data' => $validatedData
            ]);
            return response()->json(['message' => 'Error updating campaign', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Send or schedule the email campaign.
     */
    public function sendCampaign(Request $request, EmailCampaign $campaign): JsonResponse
    {
        if ($campaign->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $request->validate([
            'test_email' => 'nullable|email',
            'is_test' => 'nullable|boolean',
            'template_variables' => 'nullable|array',
        ]);
        
        // Log template variables if they exist
        if ($request->has('template_variables')) {
            Log::info('Template variables received:', [
                'campaign_id' => $campaign->id,
                'template_variables' => $request->input('template_variables')
            ]);
        }
        
        // Log the email template HTML content
        Log::info('Email template content:', [
            'campaign_id' => $campaign->id,
            'subject' => $campaign->subject,
            'html_content' => $campaign->html_content
        ]);

        // Ensure campaign is in a sendable state
        if (!in_array($campaign->status, ['draft', 'scheduled', 'failed'])) { // Allow retry for 'failed'
            return response()->json(['message' => 'Campaign cannot be sent in its current status: ' . $campaign->status], 400);
        }
        
        // Ensure campaign has recipients defined
        if (!$campaign->all_users && empty($campaign->user_ids)) {
            return response()->json(['message' => 'Campaign has no recipients defined. Please select users.'], 400);
        }

        // If scheduled, ensure it's time (simplified check)
        if ($campaign->status === 'scheduled' && $campaign->scheduled_at && $campaign->scheduled_at->isFuture()) {
            return response()->json(['message' => 'Campaign is scheduled for a future date and cannot be sent manually now unless status is changed to draft.'], 400);
        }
        
        // Determine if this is a test send or a real campaign send
        $isTest = $request->input('is_test', false);
        $testEmail = $request->input('test_email');
        
        // For test sends, we need a test email
        if ($isTest && !$testEmail) {
            return response()->json(['message' => 'Test email is required for test sends', 'errors' => ['test_email' => ['The test email field is required for test sends.']]], 422);
        }
        
        // For real campaign sends, we need recipients in the campaign
        if (!$isTest) {
            $recipients = $campaign->getRecipientEmails();
            if (empty($recipients)) {
                return response()->json(['message' => 'Campaign has no recipients', 'errors' => ['recipients' => ['The campaign must have recipients to send.']]], 422);
            }
        }
        
        // Set the recipient email based on whether this is a test or real send
        $recipientEmail = $isTest ? $testEmail : $campaign->getRecipientEmails();

        try {
            $campaign->status = 'sending';
            $campaign->save();
            
            Log::info("Attempting to send campaign email", [
                'campaign_id' => $campaign->id,
                'recipient' => $recipientEmail,
                'from_email' => $campaign->from_email,
                'from_name' => $campaign->from_name,
                'subject' => $campaign->subject
            ]);

            // Get SMTP configuration for sending
            $smtpConfig = \App\Models\SmtpConfiguration::where('is_default', true)->first();
            if ($smtpConfig) {
                // Log::info("Using SMTP configuration", [
                //     'host' => $smtpConfig->host,
                //     'port' => $smtpConfig->port,
                //     'encryption' => $smtpConfig->encryption,
                //     'username' => $smtpConfig->username,
                //     'from_address' => $smtpConfig->from_address
                // ]);
                
                // Manually override the mail configuration
                Config::set('mail.default', 'smtp');
                Config::set('mail.mailers.smtp.transport', 'smtp');
                Config::set('mail.mailers.smtp.host', $smtpConfig->host);
                Config::set('mail.mailers.smtp.port', (int) $smtpConfig->port);
                Config::set('mail.mailers.smtp.encryption', $smtpConfig->encryption);
                Config::set('mail.mailers.smtp.username', $smtpConfig->username);
                Config::set('mail.mailers.smtp.password', $smtpConfig->password);
                Config::set('mail.from.address', $smtpConfig->from_address);
                Config::set('mail.from.name', $smtpConfig->from_name);
                
                // Force Laravel to forget any existing mailer instances
                app()->forgetInstance('swift.mailer');
                app()->forgetInstance('mailer');
            } else {
                Log::warning("No default SMTP configuration found");
            }

            // Handle different sending logic based on test vs real campaign
            if ($isTest) {
                // For test sends, just send to the test email with template variables
                $templateVariables = $request->input('template_variables', []);
                Mail::to($testEmail)->send(new CampaignMail($campaign, $templateVariables));
                Log::info("Test email sent successfully to {$testEmail}", [
                    'template_variables_applied' => !empty($templateVariables)
                ]);
                
                return response()->json([
                    'message' => 'Test campaign email sent successfully to ' . $testEmail,
                    'campaign' => $campaign
                ]);
            } else {
                // Get recipient OBJECTS (not just emails) based on the campaign's selection criteria
                $recipients = $campaign->getRecipients();
                $sentCount = 0;
                
                // Update total recipients count
                $campaign->total_recipients = $recipients->count();
                $campaign->save();
                
                // This is a simplified implementation - in a real system you might queue these
                // or use a service like Mailgun's batch sending
                foreach ($recipients as $recipient) {
                    if (filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
                        // Get base template variables from request
                        $baseTemplateVariables = $request->input('template_variables', []);
                        
                        // Generate personalized variables for this specific recipient
                        $personalizedVariables = $this->generatePersonalizedVariables($recipient, $baseTemplateVariables);
                        
                        // Send with personalized variables
                        Mail::to($recipient->email)->send(new CampaignMail($campaign, $personalizedVariables));
                        $sentCount++;
                        
                        Log::info("Campaign email sent to {$recipient->email}", [
                            'recipient_id' => $recipient->id,
                            'recipient_name' => $recipient->name,
                            'personalized_variables' => $personalizedVariables
                        ]);
                    } else {
                        Log::warning("Invalid email in campaign recipients: {$recipient->email}");
                    }
                }
                
                Log::info("Campaign sent successfully to {$sentCount} recipients");
                
                $campaign->status = 'sent';
                $campaign->sent_at = now();
                // Update send count if the column exists
                if (Schema::hasColumn('email_campaigns', 'send_count')) {
                    $campaign->send_count = ($campaign->send_count ?? 0) + 1;
                }
                $campaign->save();
                
                return response()->json([
                    'message' => "Campaign sent successfully to {$sentCount} recipients",
                    'campaign' => $campaign
                ]);
            }

        } catch (\Exception $e) {
            Log::error("Error sending campaign {$campaign->id}: " . $e->getMessage(), [
                'campaign_id' => $campaign->id,
                'recipients' => is_array($recipientEmail) ? implode(', ', $recipientEmail) : $recipientEmail
            ]);
            $campaign->status = 'failed';
            $campaign->save();
            return response()->json(['message' => 'Failed to send campaign email.', 'error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Generate personalized template variables for a specific recipient.
     * Combines base variables with user-specific data from the database.
     *
     * @param  \App\Models\User  $user
     * @param  array  $baseVariables
     * @return array
     */
    protected function generatePersonalizedVariables($user, array $baseVariables = []): array
    {
        // Start with the base variables
        $personalizedVariables = $baseVariables;
        
        // Add user-specific variables from the database
        $personalizedVariables['firstName'] = explode(' ', $user->name)[0] ?? $user->name;
        $personalizedVariables['fullName'] = $user->name;
        $personalizedVariables['email'] = $user->email;
        $personalizedVariables['userId'] = $user->id;
        
        // You can add more user attributes here as needed
        // For example, if your User model has custom fields:
        // $personalizedVariables['company'] = $user->company;
        // $personalizedVariables['phone'] = $user->phone;
        
        // Log the personalization for debugging
        Log::info("Generated personalized variables for user {$user->id}", [
            'user' => $user->id,
            'name' => $user->name,
            'variables' => $personalizedVariables
        ]);
        
        return $personalizedVariables;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(EmailCampaign $campaign): JsonResponse
    {
        // Add authorization: Ensure the user can delete this campaign
        if ($campaign->user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Potentially prevent deletion based on status (e.g., if 'sending' or 'sent')
        if (in_array($campaign->status, ['sending', 'sent'])) {
             return response()->json(['message' => 'Cannot delete a campaign that is sending or has been sent. Consider archiving.'], 400);
        }

        try {
            $campaign->delete();
            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error("Error deleting campaign {$campaign->id}: " . $e->getMessage());
            return response()->json(['message' => 'Error deleting campaign', 'error' => $e->getMessage()], 500);
        }
    }

}

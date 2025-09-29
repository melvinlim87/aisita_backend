<?php

namespace App\Http\Controllers;

use App\Mail\TestTemplateMail;
use App\Models\EmailTemplate;
use App\Models\SmtpConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Config;

class TestEmailController extends Controller
{
    /**
     * Send a test email to verify SMTP configuration
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function sendTestEmail(Request $request): JsonResponse
    {
        $request->validate([
            'to_email' => 'required|email',
            'subject' => 'nullable|string|max:255',
            'content' => 'nullable|string',
            'template_id' => 'nullable|exists:email_templates,id',
            'use_template' => 'nullable|boolean',
        ]);

        $toEmail = $request->input('to_email');
        $subject = $request->input('subject', 'SMTP Test Email');
        $content = $request->input('content', 'This is a test email to verify SMTP configuration is working correctly.');
        $useTemplate = $request->input('use_template', false);
        $templateId = $request->input('template_id');

        try {
            // Get current SMTP configuration for logging
            $smtpConfig = SmtpConfiguration::where('is_default', true)->first();
            
            // if ($smtpConfig) {
            //     Log::info("Using SMTP configuration for test email", [
            //         'host' => $smtpConfig->host,
            //         'port' => $smtpConfig->port,
            //         'encryption' => $smtpConfig->encryption,
            //         'username' => $smtpConfig->username,
            //         'from_address' => $smtpConfig->from_address,
            //         'driver' => $smtpConfig->driver ?? 'smtp'
            //     ]);
            // } else {
            //     Log::warning("No default SMTP configuration found for test email");
            // }

            // Only proceed if we have SMTP configuration
            if (!$smtpConfig) {
                return response()->json([
                    'success' => false,
                    'message' => 'No default SMTP configuration found',
                ], 500);
            }

            // Manually override the mail configuration
            Config::set('mail.default', 'smtp');
            Config::set('mail.mailers.smtp.transport', 'smtp');
            Config::set('mail.mailers.smtp.host', $smtpConfig->host);
            Config::set('mail.mailers.smtp.port', (int) $smtpConfig->port);
            Config::set('mail.mailers.smtp.encryption', $smtpConfig->encryption);
            Config::set('mail.mailers.smtp.username', $smtpConfig->username);
            Config::set('mail.mailers.smtp.password', $smtpConfig->password); // This should be decrypted by the accessor
            Config::set('mail.from.address', $smtpConfig->from_address);
            Config::set('mail.from.name', $smtpConfig->from_name ?? 'Test Email');
            
            // Force Laravel to forget any existing mailer instances
            app()->forgetInstance('swift.mailer');
            app()->forgetInstance('mailer');
            
            // Check if we should use a template
            if ($useTemplate && $templateId) {
                // Get the email template
                $template = EmailTemplate::find($templateId);
                
                if (!$template) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Template not found',
                    ], 404);
                }
                
                // Use the template's subject if none provided
                $emailSubject = $subject ?: $template->subject;
                $fromEmail = $smtpConfig->from_address;
                $fromName = $smtpConfig->from_name ?? 'Test Email';
                
                // Send email using the template and our TestTemplateMail class
                Mail::to($toEmail)->send(new TestTemplateMail(
                    $emailSubject,
                    $template->html_content,
                    $fromEmail,
                    $fromName
                ));
                
                Log::info("Test email sent successfully using template #{$templateId} to {$toEmail}");
            } else {
                // Send a simple test email with plain text or HTML content
                if (strip_tags($content) !== $content) {
                    // Content contains HTML tags, send as HTML
                    Mail::html($content, function ($message) use ($toEmail, $subject, $smtpConfig) {
                        $message->to($toEmail)
                                ->subject($subject);
                        $message->from($smtpConfig->from_address, $smtpConfig->from_name ?? 'Test Email');
                    });
                } else {
                    // Plain text content
                    Mail::raw($content, function ($message) use ($toEmail, $subject, $smtpConfig) {
                        $message->to($toEmail)
                                ->subject($subject);
                        $message->from($smtpConfig->from_address, $smtpConfig->from_name ?? 'Test Email');
                    });
                }
                
                Log::info("Test email sent successfully to {$toEmail}");
            }
            
            $responseData = [
                'success' => true,
                'message' => "Test email sent to {$toEmail}. Please check your inbox and spam folder."
            ];
            
            // Add template information if a template was used
            if ($useTemplate && $templateId && isset($template)) {
                $responseData['template'] = [
                    'id' => $template->id,
                    'name' => $template->name,
                    'subject' => $template->subject
                ];
            }
            
            return response()->json($responseData);
            
        } catch (\Exception $e) {
            Log::error("Error sending test email: " . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to send test email',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

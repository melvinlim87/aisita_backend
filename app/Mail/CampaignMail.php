<?php

namespace App\Mail;

use App\Models\EmailCampaign;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CampaignMail extends Mailable
{
    use Queueable, SerializesModels;

    public EmailCampaign $campaign;
    public array $templateVariables = [];
    public $processedHtmlContent;

    /**
     * Create a new message instance.
     *
     * @param  \App\Models\EmailCampaign  $campaign
     * @param  array  $templateVariables
     */
    public function __construct(EmailCampaign $campaign, array $templateVariables = [])
    {
        $this->campaign = $campaign;
        $this->templateVariables = $templateVariables;
        $this->processedHtmlContent = $this->processHtmlContent();
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        // Get the authorized sender from SMTP configuration
        $smtpConfig = \App\Models\SmtpConfiguration::where('is_default', true)->first();
        
        // Use the authorized sender from SMTP config but keep the display name from campaign
        $fromEmail = $smtpConfig ? $smtpConfig->from_address : $this->campaign->from_email;
        
        return $this->from($fromEmail, $this->campaign->from_name)
                    ->subject($this->campaign->subject)
                    ->markdown('emails.campaign.default', [
                        'campaign' => $this->campaign,
                        'processedHtmlContent' => $this->processedHtmlContent,
                    ]);
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
    
    /**
     * Process HTML content by replacing template variables.
     * Creates a copy of the original content so the template remains reusable.
     *
     * @return string
     */
    protected function processHtmlContent(): string
    {
        // Create a copy of the original content to preserve the template
        $content = $this->campaign->html_content;
        
        // Process each template variable
        foreach ($this->templateVariables as $key => $value) {
            // Only replace exact variable placeholders to avoid changing the template structure
            // Replace {{variable}} format (with exact matches)
            $content = preg_replace('/\{\{' . preg_quote($key, '/') . '\}\}/', $value, $content);
            // Also replace %variable% format for compatibility (with exact matches)
            $content = preg_replace('/%' . preg_quote($key, '/') . '%/', $value, $content);
        }
        
        return $content;
    }
}

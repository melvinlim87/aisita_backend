<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class TestTemplateMail extends Mailable
{
    use Queueable, SerializesModels;

    public $subject;
    public $htmlContent;
    public $fromEmail;
    public $fromName;

    /**
     * Create a new message instance.
     *
     * @param string $subject
     * @param string $htmlContent
     * @param string $fromEmail
     * @param string $fromName
     */
    public function __construct($subject, $htmlContent, $fromEmail, $fromName)
    {
        $this->subject = $subject;
        $this->htmlContent = $htmlContent;
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->from($this->fromEmail, $this->fromName)
                    ->subject($this->subject)
                    ->markdown('emails.test.template', [
                        'content' => $this->htmlContent,
                    ]);
    }
}

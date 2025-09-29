<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class AnalysisResultMail extends Mailable
{
    use Queueable, SerializesModels;

    public $analysisData;
    public $images;

    /**
     * Create a new message instance.
     *
     * @param array $analysisData
     */
    public function __construct($analysisData, $images = null)
    {
        $this->analysisData = $analysisData;
        $this->images = $images;
    }

    public function build()
    {
        $smtpConfig = \App\Models\SmtpConfiguration::where('is_default', true)->first();
        
        // Use the authorized sender from SMTP config but keep the display name from campaign
        $fromEmail = $smtpConfig->from_address;
        
        $email = $this
                    ->from($fromEmail)
                    ->subject('Your Chart Analysis Result')
                    ->markdown('emails.analysis.result', [
                        'analysisData' => $this->analysisData,
                        'images' => $this->images
                    ]);
                    
        // if ($this->images) {
        //     foreach ($this->images as $image) {
        //         if (is_array($image) && isset($image['data'], $image['content_type'])) {
        //             $tmpPath = tempnam(sys_get_temp_dir(), 'chart_');
        //             file_put_contents($tmpPath, base64_decode($image['data']));
        //             $email->attach($tmpPath, [
        //                 'as'   => 'chart.png',
        //                 'mime' => $image['content_type'],
        //             ]);
        //         }
        //     }
        // }

        \Log::info('Done Adding attachment');

        return $email;
    }
}

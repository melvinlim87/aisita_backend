<?php

namespace App\Listeners;

use App\Models\SmtpConfiguration;
use Illuminate\Support\Facades\Config;

use Illuminate\Support\Facades\Log;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Queue\InteractsWithQueue;

class ConfigureMailDynamically
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        // Constructor can be empty if no dependencies are needed here
    }

    /**
     * Handle the event.
     *
     * @param  \Illuminate\Mail\Events\MessageSending  $event
     * @return void
     */
    public function handle(MessageSending $event): void
    {
        // Force Laravel to use SMTP instead of log mailer
        Config::set('mail.default', 'smtp');
        
        $smtpConfig = SmtpConfiguration::where('is_default', true)->first();

        if ($smtpConfig) {
            try {
                Config::set('mail.default', $smtpConfig->driver ?? 'smtp');

                $mailerConfig = [
                    'transport'   => $smtpConfig->driver ?? 'smtp',
                    'host'        => $smtpConfig->host,
                    'port'        => (int) $smtpConfig->port,
                    'encryption'  => $smtpConfig->encryption,
                    'username'    => $smtpConfig->username,
                    // The SmtpConfiguration model's getPasswordAttribute accessor handles decryption.
                    'password'    => $smtpConfig->password, // Accessor returns decrypted password or null

                    'timeout'     => null,
                    'local_domain' => env('MAIL_EHLO_DOMAIN'), // Or parse from APP_URL
                ];

                // If using a specific driver like mailgun, you might need to set its specific config
                $driver = $smtpConfig->driver ?? 'smtp';
                Config::set('mail.mailers.' . $driver, $mailerConfig);
                
                // Add specific Mailgun API configuration if using mailgun driver
                if ($driver === 'mailgun') {
                    Config::set('services.mailgun.domain', $smtpConfig->mailgun_domain ?? env('MAILGUN_DOMAIN'));
                    Config::set('services.mailgun.secret', $smtpConfig->mailgun_secret ?? env('MAILGUN_SECRET'));
                    Config::set('services.mailgun.endpoint', $smtpConfig->mailgun_endpoint ?? env('MAILGUN_ENDPOINT', 'api.mailgun.net'));
                }

                Config::set('mail.from.address', $smtpConfig->from_address);
                Config::set('mail.from.name', $smtpConfig->from_name);

                // Forcing Laravel to re-initialize the mailer with the new config
                // This might be necessary if the mailer instance was already resolved.
                app()->forgetInstance('swift.mailer');
                app()->forgetInstance('mailer');
                app()->make('mailer');

                Log::info('Mail configuration dynamically updated for sending.');

            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                Log::error('Failed to decrypt SMTP password during dynamic mail configuration: ' . $e->getMessage());
                // Optionally, prevent mail sending or switch to a log mailer
                // For now, it will likely fail if password was required and couldn't be decrypted.
            } catch (\Exception $e) {
                Log::error('General error during dynamic mail configuration: ' . $e->getMessage());
            }
        } else {
            Log::warning('No default SMTP configuration found in database. Using application default mail settings.');
        }
    }
}

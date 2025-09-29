<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Telegram\Bot\Laravel\Facades\Telegram;

class SetTelegramWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:set-webhook {--remove : Remove the webhook}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set or remove the Telegram webhook URL';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $remove = $this->option('remove');
        
        if ($remove) {
            $this->removeWebhook();
        } else {
            $this->setWebhook();
        }
    }

    /**
     * Set the webhook URL
     */
    private function setWebhook()
    {
        $webhook = config('telegram.bots.decyphers.webhook_url');
        
        if (empty($webhook)) {
            $this->error('Webhook URL is not set in config or .env file.');
            return;
        }

        $this->info('Setting webhook to: ' . $webhook);
        
        try {
            $response = Telegram::setWebhook(['url' => $webhook]);
            $this->info('Response: ' . json_encode($response));
            
            if ($response) {
                $this->info('Webhook has been set successfully!');
                
                // Get webhook info
                $webhookInfo = Telegram::getWebhookInfo();
                $this->table(['Key', 'Value'], $this->formatWebhookInfo($webhookInfo));
            } else {
                $this->error('Failed to set webhook.');
            }
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Remove the webhook
     */
    private function removeWebhook()
    {
        $this->info('Removing webhook...');
        
        try {
            $response = Telegram::removeWebhook();
            
            if ($response) {
                $this->info('Webhook has been removed successfully!');
            } else {
                $this->error('Failed to remove webhook.');
            }
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
        }
    }

    /**
     * Format webhook info for table display
     *
     * @param array $webhookInfo
     * @return array
     */
    private function formatWebhookInfo($webhookInfo)
    {
        $result = [];
        
        foreach ($webhookInfo as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $value = json_encode($value);
            }
            
            $result[] = [$key, $value];
        }
        
        return $result;
    }
}

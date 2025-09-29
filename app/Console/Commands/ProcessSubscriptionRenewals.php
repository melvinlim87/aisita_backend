<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\SubscriptionService;
use Illuminate\Support\Facades\Log;

class ProcessSubscriptionRenewals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'subscriptions:process-renewals';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process subscription renewals that are due today';

    /**
     * The subscription service instance.
     *
     * @var \App\Services\SubscriptionService
     */
    protected $subscriptionService;

    /**
     * Create a new command instance.
     */
    public function __construct(SubscriptionService $subscriptionService)
    {
        parent::__construct();
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Processing subscription renewals...');
        
        try {
            $dueSubscriptions = $this->subscriptionService->getSubscriptionsDueForRenewal();
            
            $this->info("Found {$dueSubscriptions->count()} subscriptions due for renewal.");
            
            foreach ($dueSubscriptions as $subscription) {
                $this->info("Processing subscription #{$subscription->id} for user #{$subscription->user_id}");
                
                $result = $this->subscriptionService->processRenewal($subscription);
                
                if ($result) {
                    $this->info("Successfully renewed subscription #{$subscription->id}");
                } else {
                    $this->error("Failed to renew subscription #{$subscription->id}");
                }
            }
            
            $this->info('Subscription renewal processing completed.');
            return 0;
        } catch (\Exception $e) {
            $this->error("Error processing subscription renewals: {$e->getMessage()}");
            Log::error("Subscription renewal processing error: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            return 1;
        }
    }
}

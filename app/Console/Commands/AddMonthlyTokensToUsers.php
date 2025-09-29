<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AddMonthlyTokensToUsers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:add-monthly-tokens {amount=15000 : The amount of tokens to add to each user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add monthly tokens to all users';

    /**
     * Create a new command instance.
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting to add monthly tokens to users...');
        $tokenAmount = (int) $this->argument('amount');
        
        try {
            // Start a database transaction
            DB::beginTransaction();
            
            // Get all users
            $users = User::all();
            $count = $users->count();
            
            $this->info("Adding {$tokenAmount} tokens to {$count} users.");
            
            $updatedCount = 0;
            
            foreach ($users as $user) {
                // For users without an active subscription, reset free tokens (no rollover)
                if (!$user->hasActiveSubscription()) {
                    $previousFreeTokens = $user->free_token ?? 0;
                    $user->free_token = $tokenAmount; // Reset and add new tokens
                    Log::info("Free user token reset: User ID {$user->id}, previous free tokens {$previousFreeTokens} reset and {$tokenAmount} new tokens added");
                } else {
                    // For users with active subscriptions, RESET ONLY subscription tokens (no rollover)
                    $previousSubscriptionTokens = $user->subscription_token ?? 0;
                    $user->subscription_token = $tokenAmount; // Reset to the new monthly allocation
                    
                    // Preserve free_token and addons_token for paid users (no reset)
                    $freeTokens = $user->free_token ?? 0;
                    $addonsTokens = $user->addons_token ?? 0;
                    
                    Log::info("Paid user subscription token reset: User ID {$user->id}, previous subscription tokens {$previousSubscriptionTokens} reset to {$tokenAmount}, preserved {$freeTokens} free tokens and {$addonsTokens} addon tokens");
                }
                
                $user->save();
                $updatedCount++;
                
                if ($updatedCount % 100 === 0) {
                    $this->info("Processed {$updatedCount} of {$count} users...");
                }
            }
            
            // Commit the transaction
            DB::commit();
            
            $this->info("Successfully added {$tokenAmount} tokens to {$updatedCount} users.");
            Log::info("Monthly token distribution: Added {$tokenAmount} tokens to {$updatedCount} users.");
            
            return 0;
        } catch (\Exception $e) {
            // Rollback the transaction in case of error
            DB::rollBack();
            
            $this->error("Error adding monthly tokens: {$e->getMessage()}");
            Log::error("Monthly token distribution error: {$e->getMessage()}", [
                'exception' => $e,
            ]);
            
            return 1;
        }
    }
}

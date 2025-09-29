<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Plan;

return new class extends Migration
{
    /**
     * Original plan token values before migration for rollback purposes
     */
    protected $originalValues = [
        'Basic' => 100000,
        'Pro' => 350000,
        'Enterprise' => 1050000,
    ];
    
    /**
     * New token values to set for each plan
     */
    protected $newValues = [
        'Basic' => 100000,    // As shown in the image
        'Pro' => 350000,      // As shown in the image
        'Enterprise' => 1050000  // As shown in the image
    ];
    
    /**
     * Free plan details to be created
     */
    protected $freePlan = [
        'name' => 'Free',
        'description' => 'Free plan with limited features and 12,000 tokens',
        'price' => 0.00,
        'currency' => 'usd',
        'interval' => 'monthly',
        'tokens_per_cycle' => 12000,
        'features' => [
            'Limited access to AI models',
            'Basic text analysis',
            'Community support'
        ],
        'premium_models_access' => false,
        'stripe_price_id' => null,
        'is_active' => true
    ];
    
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create Free plan if it doesn't exist
        $freePlanExists = Plan::where('name', 'Free')->exists();
        if (!$freePlanExists) {
            $freePlan = Plan::create($this->freePlan);
            
            try {
                if (Schema::hasTable('migration_log')) {
                    DB::table('migration_log')->insert([
                        'migration' => '2025_09_02_172743_update_plan_token_values',
                        'message' => "Created new 'Free' plan with {$this->freePlan['tokens_per_cycle']} tokens",
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
                
                // Also log to the Laravel log
                Log::info("Created new 'Free' plan with {$this->freePlan['tokens_per_cycle']} tokens");
            } catch (\Exception $e) {
                Log::warning("Could not log migration update: {$e->getMessage()}");
            }
        }
        
        // Store current values for rollback    
        $plans = Plan::all();
        foreach ($plans as $plan) {
            // Store current values if not already in our rollback array
            if (!isset($this->originalValues[$plan->name])) {
                $this->originalValues[$plan->name] = $plan->tokens_per_cycle;
            }
        }
        
        // Update token values for each plan by name
        foreach ($this->newValues as $planName => $tokenValue) {
            Plan::where('name', $planName)->update([
                'tokens_per_cycle' => $tokenValue
            ]);
            
            // Log the update - safely check if migration_log exists first
            try {
                if (Schema::hasTable('migration_log')) {
                    DB::table('migration_log')->insert([
                        'migration' => '2025_09_02_172743_update_plan_token_values',
                        'message' => "Updated plan '{$planName}' tokens to {$tokenValue}",
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
                
                // Also log to the Laravel log
                Log::info("Updated plan '{$planName}' tokens from {$this->originalValues[$planName]} to {$tokenValue}");
            } catch (\Exception $e) {
                Log::warning("Could not log migration update: {$e->getMessage()}");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove Free plan if it was created in this migration
        $freePlanExists = Plan::where('name', 'Free')->exists();
        if ($freePlanExists && !isset($this->originalValues['Free'])) {
            Plan::where('name', 'Free')->delete();
            Log::info("Removed 'Free' plan during migration rollback");
        }
        
        // Restore original token values
        foreach ($this->originalValues as $planName => $tokenValue) {
            Plan::where('name', $planName)->update([
                'tokens_per_cycle' => $tokenValue
            ]);
        }
    }
};

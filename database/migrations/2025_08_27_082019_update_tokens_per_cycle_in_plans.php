<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Plan;

return new class extends Migration
{
    /**
     * Original plan token values before migration for rollback purposes
     */
    protected $originalValues = [
        'Basic' => 4500,
        'Pro' => 10500,
        'Enterprise' => 15000, // Assuming this is the original value
    ];
    
    /**
     * New token values to set for each plan
     */
    protected $newValues = [
        'Basic' => 165000,    // As specified by user
        'Pro' => 384999,      // As specified by user
        'Enterprise' => 1650000  // As specified by user
    ];
    
    /**
     * Run the migrations.
     */
    public function up(): void
    {
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
                        'migration' => '2025_08_27_082019_update_tokens_per_cycle_in_plans',
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
        // Restore original token values
        foreach ($this->originalValues as $planName => $tokenValue) {
            Plan::where('name', $planName)->update([
                'tokens_per_cycle' => $tokenValue
            ]);
        }
    }
};

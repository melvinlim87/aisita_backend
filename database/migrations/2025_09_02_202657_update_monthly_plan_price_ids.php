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
     * New price IDs for monthly plans
     */
    protected $newPriceIds = [
        'Basic' => 'price_1S2sxC2MDBrgqcCAPtRcfmLE',
        'Pro' => 'price_1S2sxj2MDBrgqcCAmE4CiNVz',
        'Enterprise' => 'price_1S2syQ2MDBrgqcCAdyLgRQPj',
    ];

    /**
     * Original price IDs (for rollback purposes)
     */
    protected $originalPriceIds = [];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Store original price IDs for rollback
        $monthlyPlans = Plan::where('interval', 'monthly')
            ->whereIn('name', array_keys($this->newPriceIds))
            ->get();
            
        foreach ($monthlyPlans as $plan) {
            $this->originalPriceIds[$plan->name] = $plan->stripe_price_id;
        }
        
        // Update price IDs for monthly plans
        foreach ($this->newPriceIds as $planName => $priceId) {
            $plan = Plan::where('name', $planName)
                ->where('interval', 'monthly')
                ->first();
            
            if ($plan) {
                $plan->stripe_price_id = $priceId;
                $plan->save();
                
                try {
                    if (Schema::hasTable('migration_log')) {
                        DB::table('migration_log')->insert([
                            'migration' => '2025_09_02_202657_update_monthly_plan_price_ids',
                            'message' => "Updated '{$planName}' monthly plan price ID to '{$priceId}'",
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                    
                    // Also log to the Laravel log
                    Log::info("Updated '{$planName}' monthly plan price ID to '{$priceId}'");
                } catch (\Exception $e) {
                    Log::warning("Could not log migration update: {$e->getMessage()}");
                }
            } else {
                Log::warning("Monthly plan '{$planName}' not found");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore original price IDs
        foreach ($this->originalPriceIds as $planName => $priceId) {
            $plan = Plan::where('name', $planName)
                ->where('interval', 'monthly')
                ->first();
            
            if ($plan) {
                $plan->stripe_price_id = $priceId;
                $plan->save();
                
                Log::info("Rolled back '{$planName}' monthly plan price ID to original value");
            }
        }
    }
};

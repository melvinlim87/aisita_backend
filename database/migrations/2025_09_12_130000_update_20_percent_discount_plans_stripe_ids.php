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
     * Store the original stripe price IDs for rollback
     */
    protected $originalPriceIds = [];
    
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            // Define the plans and their price IDs
            $planUpdates = [
                'Enterprise Annual (20% Off)' => 'price_1RkiHb2MDBrgqcCAHx1ADJn9',
                'Basic Annual (20% Off)' => 'price_1RkiGS2MDBrgqcCAPu1ILnrx',
                'Pro Annual (20% Off)' => 'price_1RkiH32MDBrgqcCAyJTES8hR'
            ];
            
            // Update each plan with its Stripe price ID
            foreach ($planUpdates as $planName => $stripeId) {
                $plan = Plan::where('name', $planName)->first();
                
                if ($plan) {
                    // Store original price ID for rollback
                    $this->originalPriceIds[$plan->id] = $plan->stripe_price_id;
                    
                    // Update the price ID
                    $plan->stripe_price_id = $stripeId;
                    $plan->save();
                    
                    Log::info("Updated Stripe price ID for {$planName} plan to {$stripeId}");
                } else {
                    Log::warning("{$planName} plan not found, could not update Stripe price ID");
                }
            }
        } catch (\Exception $e) {
            Log::error("Error updating Stripe price IDs: {$e->getMessage()}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            // Restore original Stripe price IDs
            foreach ($this->originalPriceIds as $planId => $originalPriceId) {
                $plan = Plan::find($planId);
                
                if ($plan) {
                    $plan->stripe_price_id = $originalPriceId;
                    $plan->save();
                    
                    Log::info("Restored original Stripe price ID for plan {$plan->name}");
                }
            }
        } catch (\Exception $e) {
            Log::error("Error restoring Stripe price IDs: {$e->getMessage()}");
        }
    }
};

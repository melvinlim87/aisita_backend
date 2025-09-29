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
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            // Step 1: Delete duplicate plans (IDs 11-17)
            $duplicateIds = range(11, 17);
            Plan::whereIn('id', $duplicateIds)->delete();
            Log::info('Deleted duplicate plans with IDs 11-17');
            
            // Step 2: Delete incorrect free plan with ID 18
            Plan::where('id', 18)->delete();
            Log::info('Deleted incorrect free plan with ID 18');
            
            // Step 3: Fix inconsistent features formatting
            $this->fixFeaturesFormat();
            
            // Step 4: Add missing plans with correct values
            $this->addMissingPlans();
            
            Log::info('Plans table cleanup completed successfully');
        } catch (\Exception $e) {
            Log::error('Error cleaning up plans table: ' . $e->getMessage());
        }
    }

    /**
     * Fix features format for plans where it's stored as escaped JSON string
     */
    private function fixFeaturesFormat()
    {
        $plans = Plan::all();
        
        foreach ($plans as $plan) {
            // If features is a JSON string with escaped quotes
            if (is_string($plan->features) && str_starts_with($plan->features, '"[')) {
                try {
                    // Remove outer quotes, unescape inner quotes
                    $cleaned = trim($plan->features, '"');
                    $unescaped = str_replace('\\\"', '"', $cleaned);
                    
                    // Parse JSON
                    $features = json_decode($unescaped);
                    
                    // Update with properly formatted array
                    $plan->features = $features;
                    $plan->save();
                    
                    Log::info("Fixed features format for plan ID: {$plan->id}");
                } catch (\Exception $e) {
                    Log::error("Failed to fix features for plan ID {$plan->id}: {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Add missing plans
     */
    private function addMissingPlans()
    {
        // Enterprise yearly plan at $950.40
        $enterpriseYearly = new Plan();
        $enterpriseYearly->name = 'Enterprise Annual';
        $enterpriseYearly->description = 'Get 12,600,000 tokens per year at our standard price of $950.40 ($79.20/month)';
        $enterpriseYearly->price = 950.40;
        $enterpriseYearly->currency = 'usd';
        $enterpriseYearly->interval = 'yearly';
        $enterpriseYearly->tokens_per_cycle = 12600000;
        $enterpriseYearly->support_available = true;
        $enterpriseYearly->features = [
            "Access to all AI models",
            "Unlimited text and image analysis",
            "Priority support with dedicated account manager",
            "All premium features",
            "Custom integrations"
        ];
        $enterpriseYearly->stripe_price_id = null; // This will need to be updated manually later
        $enterpriseYearly->is_active = true;
        $enterpriseYearly->premium_models_access = true;
        $enterpriseYearly->created_at = now();
        $enterpriseYearly->updated_at = now();
        $enterpriseYearly->save();
        
        Log::info('Added missing Enterprise Annual plan');
        
        // Check if we need to add other plans based on the requirement
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration is cleaning up data, so we don't provide a reversal
        // as it would require having backups of the deleted data
        Log::info('No down migration for cleaning plans table');
    }
};

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
            // Step 1: Fix remaining escaped JSON strings in features
            $plansWithStringFeatures = [2, 3, 4, 5, 6, 7, 8, 9, 10]; // IDs of plans with string features
            foreach ($plansWithStringFeatures as $planId) {
                $plan = Plan::find($planId);
                if ($plan && is_string($plan->features) && str_starts_with($plan->features, '"[')) {
                    // Remove outer quotes, unescape inner quotes
                    $cleaned = trim($plan->features, '"');
                    $unescaped = str_replace('\\\"', '"', $cleaned);
                    
                    // Parse JSON
                    $features = json_decode($unescaped);
                    if ($features) {
                        $plan->features = $features;
                        $plan->save();
                        Log::info("Fixed features format for plan ID: {$planId}");
                    } else {
                        Log::error("Failed to decode features JSON for plan ID: {$planId}");
                    }
                }
            }

            // Step 2: Add missing Basic and Pro monthly plans
            $this->addMissingMonthlyPlans();
            
            Log::info('Fixed remaining plan issues successfully');
        } catch (\Exception $e) {
            Log::error('Error fixing remaining plan issues: ' . $e->getMessage());
        }
    }

    /**
     * Add missing monthly plans
     */
    private function addMissingMonthlyPlans()
    {
        $monthlyPlans = [
            [
                'name' => 'Basic',
                'description' => 'Basic plan with access to non-premium models and 100,000 tokens per month',
                'price' => 19.00,
                'currency' => 'usd',
                'interval' => 'monthly',
                'tokens_per_cycle' => 100000,
                'support_available' => true,
                'features' => [
                    "Access to regular AI models",
                    "Text-based analysis",
                    "Basic support"
                ],
                'premium_models_access' => false,
                'stripe_price_id' => 'price_1S2sxC2MDBrgqcCAPtRcfmLE'
            ],
            [
                'name' => 'Pro',
                'description' => 'Pro plan with access to all models and 350,000 tokens per month',
                'price' => 49.00,
                'currency' => 'usd',
                'interval' => 'monthly',
                'tokens_per_cycle' => 350000,
                'support_available' => true,
                'features' => [
                    "Access to all AI models",
                    "Text and image analysis",
                    "Priority support",
                    "Advanced features"
                ],
                'premium_models_access' => true,
                'stripe_price_id' => 'price_1S2sxj2MDBrgqcCAmE4CiNVz'
            ],
            [
                'name' => 'Enterprise',
                'description' => 'Enterprise plan with access to all models and 1,050,000 tokens per month',
                'price' => 99.00,
                'currency' => 'usd',
                'interval' => 'monthly',
                'tokens_per_cycle' => 1050000,
                'support_available' => true,
                'features' => [
                    "Access to all AI models",
                    "Unlimited text and image analysis",
                    "Priority support with dedicated account manager",
                    "All premium features",
                    "Custom integrations"
                ],
                'premium_models_access' => true,
                'stripe_price_id' => 'price_1S2syQ2MDBrgqcCAdyLgRQPj'
            ]
        ];

        foreach ($monthlyPlans as $planData) {
            // Check if the plan already exists
            $existingPlan = Plan::where('name', $planData['name'])
                             ->where('interval', 'monthly')
                             ->first();
            
            if (!$existingPlan) {
                $plan = new Plan();
                $plan->name = $planData['name'];
                $plan->description = $planData['description'];
                $plan->price = $planData['price'];
                $plan->currency = $planData['currency'];
                $plan->interval = $planData['interval'];
                $plan->tokens_per_cycle = $planData['tokens_per_cycle'];
                $plan->support_available = $planData['support_available'];
                $plan->features = $planData['features'];
                $plan->premium_models_access = $planData['premium_models_access'];
                $plan->stripe_price_id = $planData['stripe_price_id'];
                $plan->is_active = true;
                $plan->created_at = now();
                $plan->updated_at = now();
                $plan->save();
                
                Log::info("Added {$planData['name']} monthly plan");
            } else {
                Log::info("{$planData['name']} monthly plan already exists, skipping");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Delete the monthly plans added by this migration
        $monthlyPlanNames = ['Basic', 'Pro', 'Enterprise'];
        
        foreach ($monthlyPlanNames as $planName) {
            $plans = Plan::where('name', $planName)
                    ->where('interval', 'monthly')
                    ->whereDate('created_at', '=', date('Y-m-d'))
                    ->get();
            
            foreach ($plans as $plan) {
                $plan->delete();
                Log::info("Deleted {$planName} monthly plan added by this migration");
            }
        }
    }
};

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
            // Add the regular yearly plans with 20% discount
            $this->addYearlyPlans();
            
            // Ensure all token values are correct
            $this->validateTokenValues();
            
            Log::info('Added missing 20% discount yearly plans');
        } catch (\Exception $e) {
            Log::error('Error adding yearly plans: ' . $e->getMessage());
        }
    }

    /**
     * Add yearly plans with 20% discount
     */
    private function addYearlyPlans()
    {
        $yearlyPlans = [
            [
                'name' => 'Basic Annual (20% Off)',
                'description' => 'Get 1,200,000 tokens per year with our standard 20% DISCOUNT! Perfect for individuals who need reliable access to AI capabilities.',
                'price' => 182.40, // $19 * 12 months * 0.8 (20% off)
                'regular_price' => 228.00, // $19 * 12 months
                'discount_percentage' => 20,
                'has_discount' => true,
                'currency' => 'usd',
                'interval' => 'yearly',
                'tokens_per_cycle' => 1200000, // 100,000 * 12 months
                'support_available' => true,
                'features' => [
                    "Access to regular AI models",
                    "Text-based analysis",
                    "Basic support",
                    "20% discount on annual subscription"
                ],
                'premium_models_access' => false,
                'stripe_price_id' => null // This will need to be updated manually later
            ],
            [
                'name' => 'Pro Annual (20% Off)',
                'description' => 'Access 4,200,000 tokens per year with our standard 20% DISCOUNT! Ideal for businesses and advanced users.',
                'price' => 470.40, // $49 * 12 months * 0.8 (20% off)
                'regular_price' => 588.00, // $49 * 12 months
                'discount_percentage' => 20,
                'has_discount' => true,
                'currency' => 'usd',
                'interval' => 'yearly',
                'tokens_per_cycle' => 4200000, // 350,000 * 12 months
                'support_available' => true,
                'features' => [
                    "Access to all AI models",
                    "Text and image analysis",
                    "Priority support",
                    "Advanced features",
                    "20% discount on annual subscription"
                ],
                'premium_models_access' => true,
                'stripe_price_id' => null // This will need to be updated manually later
            ]
            // Enterprise plan with 20% discount is already added in the previous migration
        ];

        foreach ($yearlyPlans as $planData) {
            // Check if the plan already exists
            $existingPlan = Plan::where('name', $planData['name'])->first();
            
            if (!$existingPlan) {
                $plan = new Plan();
                $plan->name = $planData['name'];
                $plan->description = $planData['description'];
                $plan->price = $planData['price'];
                $plan->regular_price = $planData['regular_price'];
                $plan->discount_percentage = $planData['discount_percentage'];
                $plan->has_discount = $planData['has_discount'];
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
                
                Log::info("Added {$planData['name']} plan");
            } else {
                Log::info("{$planData['name']} plan already exists, skipping");
            }
        }
    }

    /**
     * Ensure all token values are correct
     */
    private function validateTokenValues()
    {
        // Token values for standard plans
        $tokenValues = [
            'Free' => 12000,
            'Basic' => 100000,
            'Pro' => 350000,
            'Enterprise' => 1050000
        ];

        foreach ($tokenValues as $planName => $tokenValue) {
            Plan::where('name', $planName)
                ->update(['tokens_per_cycle' => $tokenValue]);
            
            Log::info("Updated token value for {$planName} plan to {$tokenValue}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Delete the plans added by this migration
        $planNames = [
            'Basic Annual (20% Off)',
            'Pro Annual (20% Off)'
        ];
        
        Plan::whereIn('name', $planNames)->delete();
        Log::info('Deleted 20% discount yearly plans added by this migration');
    }
};

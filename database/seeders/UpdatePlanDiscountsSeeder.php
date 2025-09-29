<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdatePlanDiscountsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Updates existing plans to include discount information.
     */
    public function run(): void
    {
        // Start a transaction for safety
        DB::beginTransaction();

        try {
            // Process monthly plans
            $this->updateMonthlyPlans();
            
            // Process yearly plans
            $this->updateYearlyPlans();
            
            // Add Enterprise Annual if it doesn't exist
            $this->addEnterpriseAnnualIfMissing();
            
            DB::commit();
            $this->command->info('Plans updated with discount information successfully!');
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating plans with discounts: ' . $e->getMessage());
            $this->command->error('Failed to update plans with discount information: ' . $e->getMessage());
        }
    }
    
    /**
     * Update monthly plans with 12% discount
     */
    private function updateMonthlyPlans(): void
    {
        $monthlyPlans = Plan::where('interval', 'monthly')->get();
        
        foreach ($monthlyPlans as $plan) {
            // Store original price as regular price
            $originalPrice = $plan->price;
            
            // Apply 12% discount for monthly plans
            $plan->regular_price = $originalPrice;
            $plan->discount_percentage = 12;
            $plan->price = round($originalPrice * 0.88, 2); // 12% discount
            $plan->has_discount = true;
            $plan->save();
            
            $this->command->info("Updated {$plan->name}: Original price \${$plan->regular_price}, Discounted price \${$plan->price}");
        }
    }
    
    /**
     * Update yearly plans with 34% discount compared to paying monthly
     */
    private function updateYearlyPlans(): void
    {
        $yearlyPlans = Plan::where('interval', 'yearly')->get();
        
        foreach ($yearlyPlans as $plan) {
            // Find the corresponding monthly plan to calculate proper regular price
            $monthlyPlanName = str_replace(' Annual', '', $plan->name);
            $monthlyPlan = Plan::where('name', $monthlyPlanName)->first();
            
            if ($monthlyPlan) {
                // Set regular price to 12 times the monthly regular price
                $yearlyRegularPrice = $monthlyPlan->regular_price * 12;
                
                // Apply 34% discount for yearly plans
                $plan->regular_price = $yearlyRegularPrice;
                $plan->discount_percentage = 34;
                $plan->price = round($yearlyRegularPrice * 0.66, 2); // 34% discount
                $plan->has_discount = true;
                $plan->save();
                
                $this->command->info("Updated {$plan->name}: Regular yearly price \${$plan->regular_price}, Discounted price \${$plan->price}");
            }
        }
    }
    
    /**
     * Add Enterprise Annual plan if it doesn't exist
     */
    private function addEnterpriseAnnualIfMissing(): void
    {
        $enterpriseAnnual = Plan::where('name', 'Enterprise Annual')->first();
        
        if (!$enterpriseAnnual) {
            // Get the monthly enterprise plan
            $enterpriseMonthly = Plan::where('name', 'Enterprise')->first();
            
            if ($enterpriseMonthly) {
                $yearlyRegularPrice = $enterpriseMonthly->regular_price * 12;
                $discountedPrice = round($yearlyRegularPrice * 0.66, 2); // 34% discount
                
                Plan::create([
                    'name' => 'Enterprise Annual',
                    'description' => 'Enterprise annual plan with access to all models and 24000 tokens per year',
                    'price' => $discountedPrice,
                    'regular_price' => $yearlyRegularPrice,
                    'discount_percentage' => 34,
                    'has_discount' => true,
                    'currency' => 'usd',
                    'interval' => 'yearly',
                    'tokens_per_cycle' => $enterpriseMonthly->tokens_per_cycle * 12,
                    'features' => [
                        'Access to all AI models',
                        'Unlimited text and image analysis',
                        'Priority support with dedicated account manager',
                        'All premium features',
                        'Custom integrations',
                        '4 months free compared to monthly plan'
                    ],
                    'premium_models_access' => true,
                ]);
                
                $this->command->info("Created Enterprise Annual plan: Regular price \${$yearlyRegularPrice}, Discounted price \${$discountedPrice}");
            }
        }
    }
}

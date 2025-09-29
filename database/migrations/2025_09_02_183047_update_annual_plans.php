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
     * Original annual plan values before migration for rollback purposes
     */
    protected $originalValues = [];
    
    /**
     * New annual plan details
     */
    protected $newAnnualPlans = [
        'Basic Annual' => [
            'tokens_per_cycle' => 1200000,
            'price' => 182.40,
            'description' => 'Basic annual plan with access to non-premium models and 1,200,000 tokens per year'
        ],
        'Pro Annual' => [
            'tokens_per_cycle' => 4200000,
            'price' => 470.40,
            'description' => 'Pro annual plan with access to all models and 4,200,000 tokens per year'
        ],
        'Enterprise Annual' => [
            'tokens_per_cycle' => 12600000,
            'price' => 950.40,
            'description' => 'Enterprise annual plan with access to all models and 12,600,000 tokens per year'
        ]
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // run planseeder first
        Plan::create([
            'name' => 'Basic',
            'description' => 'Basic plan with access to non-premium models and 4,500 tokens per month',
            'price' => 9.99,
            'currency' => 'usd',
            'interval' => 'monthly',
            'tokens_per_cycle' => 165000,
            'features' => [
                'Access to regular AI models',
                'Text-based analysis',
                'Basic support'
            ],
            'premium_models_access' => false,
            'stripe_price_id' => 'price_1RkiEe2MDBrgqcCA0Jxhk1Rk', // Replace with actual Stripe price ID
        ]);

        Plan::create([
            'name' => 'Pro',
            'description' => 'Pro plan with access to all models and 10,500 tokens per month',
            'price' => 19.99,
            'currency' => 'usd',
            'interval' => 'monthly',
            'tokens_per_cycle' => 350000,
            'features' => [
                'Access to all AI models',
                'Text and image analysis',
                'Priority support',
                'Advanced features'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1RkiFF2MDBrgqcCAN3oY0l3I', // Replace with actual Stripe price ID
        ]);

        Plan::create([
            'name' => 'Enterprise',
            'description' => 'Enterprise plan with access to all models and 45,000 tokens per month',
            'price' => 49.99,
            'currency' => 'usd',
            'interval' => 'monthly',
            'tokens_per_cycle' => 1050000,
            'features' => [
                'Access to all AI models',
                'Unlimited text and image analysis',
                'Priority support with dedicated account manager',
                'All premium features',
                'Custom integrations'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1RkiFq2MDBrgqcCATo5wJSbT', // Replace with actual Stripe price ID
        ]);

        // Create annual plans
        Plan::create([
            'name' => 'Basic Annual',
            'description' => 'Basic annual plan with access to non-premium models and 54,000 tokens per year',
            'price' => 99.99,
            'currency' => 'usd',
            'interval' => 'yearly',
            'tokens_per_cycle' => 54000,
            'features' => [
                'Access to regular AI models',
                'Text-based analysis',
                'Basic support',
                '2 months free compared to monthly plan'
            ],
            'premium_models_access' => false,
            'stripe_price_id' => 'price_1RkiGS2MDBrgqcCAPu1ILnrx', // Replace with actual Stripe price ID
        ]);

        Plan::create([
            'name' => 'Pro Annual',
            'description' => 'Pro annual plan with access to all models and 126,000 tokens per year',
            'price' => 199.99,
            'currency' => 'usd',
            'interval' => 'yearly',
            'tokens_per_cycle' => 126000,
            'features' => [
                'Access to all AI models',
                'Text and image analysis',
                'Priority support',
                'Advanced features',
                '2 months free compared to monthly plan'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1RkiH32MDBrgqcCAyJTES8hR', // Replace with actual Stripe price ID
        ]);
        
        // Create Enterprise Annual plan
        Plan::create([
            'name' => 'Enterprise Annual',
            'description' => 'Enterprise annual plan with access to all models and 540,000 tokens per year',
            'price' => 499.99,
            'currency' => 'usd',
            'interval' => 'yearly',
            'tokens_per_cycle' => 540000,
            'features' => [
                'Access to all AI models',
                'Unlimited text and image analysis',
                'Priority support with dedicated account manager',
                'All premium features',
                'Custom integrations',
                '2 months free compared to monthly plan'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1RkiHb2MDBrgqcCAHx1ADJn9', // Replace with actual Stripe price ID
        ]);

        // Store original values for rollback
        $annualPlans = Plan::where('interval', 'yearly')->get();
        foreach ($annualPlans as $plan) {
            $this->originalValues[$plan->name] = [
                'tokens_per_cycle' => $plan->tokens_per_cycle,
                'price' => $plan->price,
                'description' => $plan->description
            ];
        }
        
        // Update annual plans
        foreach ($this->newAnnualPlans as $planName => $planDetails) {
            $plan = Plan::where('name', $planName)->first();
            
            if ($plan) {
                $plan->tokens_per_cycle = $planDetails['tokens_per_cycle'];
                $plan->price = $planDetails['price'];
                $plan->description = $planDetails['description'];
                $plan->save();
                
                try {
                    if (Schema::hasTable('migration_log')) {
                        DB::table('migration_log')->insert([
                            'migration' => '2025_09_02_183047_update_annual_plans',
                            'message' => "Updated '{$planName}' with {$planDetails['tokens_per_cycle']} tokens and price \${$planDetails['price']}",
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                    
                    // Also log to the Laravel log
                    Log::info("Updated '{$planName}' with {$planDetails['tokens_per_cycle']} tokens and price \${$planDetails['price']}");
                } catch (\Exception $e) {
                    Log::warning("Could not log migration update: {$e->getMessage()}");
                }
            } else {
                Log::warning("Plan '{$planName}' not found");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore original values
        foreach ($this->originalValues as $planName => $details) {
            $plan = Plan::where('name', $planName)->first();
            
            if ($plan) {
                $plan->tokens_per_cycle = $details['tokens_per_cycle'];
                $plan->price = $details['price'];
                $plan->description = $details['description'];
                $plan->save();
                
                Log::info("Rolled back '{$planName}' to {$details['tokens_per_cycle']} tokens and price \${$details['price']}");
            }
        }
    }
};

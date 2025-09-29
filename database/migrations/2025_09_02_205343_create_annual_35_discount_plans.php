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
     * Annual plans with 35% discount
     */
    protected $annualPlans = [
        [
            'name' => 'Basic Annual (35% Off)',
            'stripe_price_id' => 'price_1S2tkv2MDBrgqcCAvbZHCDn0', // To be filled manually
            'price' => 148.20, // Original price * 12 * 0.65 = 19.90 * 12 * 0.65 = 155.40
            'regular_price' => 238.80, // Original monthly price * 12 = 19.90 * 12 = 238.80
            'discount_percentage' => 35,
            'has_discount' => true,
            'interval' => 'yearly',
            'tokens_per_cycle' => 1200000,
            'premium_models_access' => false,
            'description' => 'Get 1,200,000 tokens per year with our SPECIAL 35% DISCOUNT! Perfect for individuals who need reliable access to AI capabilities at a budget-friendly price.',
            'is_active' => true,
        ],
        [
            'name' => 'Pro Annual (35% Off)',
            'stripe_price_id' => 'price_1S2tlj2MDBrgqcCANdEK5tz2', // To be filled manually
            'price' => 382.20, // Original price * 12 * 0.65 = 51.50 * 12 * 0.65 = 401.70
            'regular_price' => 618.00, // Original monthly price * 12 = 51.50 * 12 = 618.00
            'discount_percentage' => 35,
            'has_discount' => true,
            'interval' => 'yearly',
            'tokens_per_cycle' => 4200000,
            'premium_models_access' => true,
            'description' => 'Access 4,200,000 tokens per year with our SPECIAL 35% DISCOUNT! Ideal for businesses and advanced users who need premium features and higher capacity.',
            'is_active' => true,
        ],
        [
            'name' => 'Enterprise Annual (35% Off)',
            'stripe_price_id' => 'price_1S2tmY2MDBrgqcCAY8NWUikU', // To be filled manually
            'price' => 772.20, // Original price * 12 * 0.65 = 104.00 * 12 * 0.65 = 811.20
            'regular_price' => 1248.00, // Original monthly price * 12 = 104.00 * 12 = 1248.00
            'discount_percentage' => 35,
            'has_discount' => true,
            'interval' => 'yearly',
            'tokens_per_cycle' => 12600000,
            'premium_models_access' => true,
            'description' => 'Unlock 12,600,000 tokens per year with our SPECIAL 35% DISCOUNT! Perfect for large teams and organizations needing the highest level of service and capacity.',
            'is_active' => true,
        ],
    ];

    /**
     * IDs of created plans (for rollback)
     */
    protected $createdPlanIds = [];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->annualPlans as $planData) {
            try {
                // Check if a similar plan already exists
                $existingPlan = Plan::where('name', $planData['name'])->first();
                
                if ($existingPlan) {
                    // Update the existing plan
                    $existingPlan->stripe_price_id = $planData['stripe_price_id'];
                    $existingPlan->price = $planData['price'];
                    $existingPlan->regular_price = $planData['regular_price'];
                    $existingPlan->discount_percentage = $planData['discount_percentage'];
                    $existingPlan->has_discount = $planData['has_discount'];
                    $existingPlan->interval = $planData['interval'];
                    $existingPlan->tokens_per_cycle = $planData['tokens_per_cycle'];
                    $existingPlan->premium_models_access = $planData['premium_models_access'];
                    $existingPlan->description = $planData['description'];
                    $existingPlan->is_active = $planData['is_active'];
                    $existingPlan->updated_at = now();
                    $existingPlan->save();
                    
                    // Store the ID for rollback
                    $this->createdPlanIds[] = $existingPlan->id;
                    
                    Log::info("Updated annual 35% discount plan: {$planData['name']}");
                } else {
                    // Create a new plan
                    $plan = new Plan();
                    $plan->name = $planData['name'];
                    $plan->stripe_price_id = $planData['stripe_price_id'];
                    $plan->price = $planData['price'];
                    $plan->regular_price = $planData['regular_price'];
                    $plan->discount_percentage = $planData['discount_percentage'];
                    $plan->has_discount = $planData['has_discount'];
                    $plan->interval = $planData['interval'];
                    $plan->tokens_per_cycle = $planData['tokens_per_cycle'];
                    $plan->premium_models_access = $planData['premium_models_access'];
                    $plan->description = $planData['description'];
                    $plan->is_active = $planData['is_active'];
                    $plan->created_at = now();
                    $plan->updated_at = now();
                    $plan->save();
                    
                    // Store the ID for rollback
                    $this->createdPlanIds[] = $plan->id;
                    
                    Log::info("Created new annual 35% discount plan: {$planData['name']}");
                }
                
                // Log to migration_log table if it exists
                try {
                    if (Schema::hasTable('migration_log')) {
                        DB::table('migration_log')->insert([
                            'migration' => '2025_09_02_205343_create_annual_35_discount_plans',
                            'message' => "Created/Updated annual 35% discount plan: {$planData['name']}",
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning("Could not log migration update: {$e->getMessage()}");
                }
            } catch (\Exception $e) {
                Log::error("Failed to create/update annual 35% discount plan: {$planData['name']} - {$e->getMessage()}");
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Delete the plans created by this migration
        Plan::whereIn('id', $this->createdPlanIds)->delete();
        Log::info("Deleted annual 35% discount plans created by this migration");
    }
};

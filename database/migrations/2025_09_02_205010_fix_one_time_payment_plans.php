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
     * One-time payment plans to create with correct field names
     */
    protected $oneTimePaymentPlans = [
        // Monthly one-time plans
        [
            'name' => 'Basic One-Time',
            'stripe_price_id' => 'price_1S2tP62MDBrgqcCAtJo2jajj',
            'price' => 13.30,
            'interval' => 'one_time',
            'tokens_per_cycle' => 100000,
            'has_premium' => false,
            'premium_models_access' => false,
            'description' => 'ONE-TIME PAYMENT of $13.30 for 100,000 tokens. This is not a recurring subscription - pay once and use your tokens when you need them. Special 30% off promotion.',
            'is_active' => true,
        ],
        [
            'name' => 'Pro One-Time',
            'stripe_price_id' => 'price_1S2tPk2MDBrgqcCA2C0438jP',
            'price' => 34.30,
            'interval' => 'one_time',
            'tokens_per_cycle' => 350000,
            'has_premium' => true,
            'premium_models_access' => true,
            'description' => 'ONE-TIME PAYMENT of $34.30 for 350,000 tokens. This is not a recurring subscription - pay once and use your tokens when you need them. Special 30% off promotion.',
            'is_active' => true,
        ],
        [
            'name' => 'Enterprise One-Time',
            'stripe_price_id' => 'price_1S2tQD2MDBrgqcCANeAvdSBS',
            'price' => 69.30,
            'interval' => 'one_time',
            'tokens_per_cycle' => 1050000,
            'has_premium' => true,
            'premium_models_access' => true,
            'description' => 'ONE-TIME PAYMENT of $69.30 for 1,050,000 tokens. This is not a recurring subscription - pay once and use your tokens when you need them. Special 30% off promotion.',
            'is_active' => true,
        ],
        
        // Yearly one-time plans
        [
            'name' => 'Basic Annual One-Time',
            'stripe_price_id' => 'price_1S2tQx2MDBrgqcCAtMOL4cta',
            'price' => 159.60,
            'interval' => 'one_time',
            'tokens_per_cycle' => 1200000,
            'has_premium' => false,
            'premium_models_access' => false,
            'description' => 'ONE-TIME PAYMENT of $159.60 for 1,200,000 tokens. This is not a recurring subscription - pay once and use your tokens throughout the year. Special 30% first-year discount.',
            'is_active' => true,
        ],
        [
            'name' => 'Pro Annual One-Time',
            'stripe_price_id' => 'price_1S2tRR2MDBrgqcCA53uaBa3x',
            'price' => 411.60,
            'interval' => 'one_time',
            'tokens_per_cycle' => 4200000,
            'has_premium' => true,
            'premium_models_access' => true,
            'description' => 'ONE-TIME PAYMENT of $411.60 for 4,200,000 tokens. This is not a recurring subscription - pay once and use your tokens throughout the year. Special 30% first-year discount.',
            'is_active' => true,
        ],
        [
            'name' => 'Enterprise Annual One-Time',
            'stripe_price_id' => 'price_1S2tS72MDBrgqcCAm0EQ2JPC',
            'price' => 831.60,
            'interval' => 'one_time',
            'tokens_per_cycle' => 12600000,
            'has_premium' => true,
            'premium_models_access' => true,
            'description' => 'ONE-TIME PAYMENT of $831.60 for 12,600,000 tokens. This is not a recurring subscription - pay once and use your tokens throughout the year. Special 30% first-year discount.',
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
        // First, remove any existing one-time plans
        Plan::where('interval', 'one_time')->delete();
        
        // Create new plans with correct field names
        foreach ($this->oneTimePaymentPlans as $planData) {
            try {
                $plan = new Plan();
                $plan->name = $planData['name'];
                $plan->stripe_price_id = $planData['stripe_price_id'];
                $plan->price = $planData['price'];
                $plan->interval = $planData['interval'];
                $plan->tokens_per_cycle = $planData['tokens_per_cycle'];
                $plan->premium_models_access = $planData['premium_models_access']; // Correctly named field
                $plan->description = $planData['description'];
                $plan->is_active = $planData['is_active']; // Correctly named field
                $plan->created_at = now();
                $plan->updated_at = now();
                $plan->save();
                
                // Store the ID for rollback
                $this->createdPlanIds[] = $plan->id;
                
                try {
                    if (Schema::hasTable('migration_log')) {
                        DB::table('migration_log')->insert([
                            'migration' => '2025_09_02_205010_fix_one_time_payment_plans',
                            'message' => "Created new one-time payment plan: {$planData['name']}",
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                    
                    // Also log to the Laravel log
                    Log::info("Created new one-time payment plan: {$planData['name']}");
                } catch (\Exception $e) {
                    Log::warning("Could not log migration update: {$e->getMessage()}");
                }
            } catch (\Exception $e) {
                Log::error("Failed to create one-time payment plan: {$planData['name']} - {$e->getMessage()}");
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
        Log::info("Deleted one-time payment plans created by this migration");
    }
};

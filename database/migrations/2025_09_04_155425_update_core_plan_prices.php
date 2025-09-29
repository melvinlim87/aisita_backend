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
     * Store the original plan prices for rollback
     */
    protected $originalPrices = [];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Define the price updates
        $priceUpdates = [
            'Basic' => 19.00,
            'Pro' => 49.00,
            'Enterprise' => 99.00
        ];

        // Find and update each plan
        foreach ($priceUpdates as $planName => $newPrice) {
            // Only update plans with monthly interval (not annual or one-time)
            $plans = Plan::where('name', $planName)
                ->where('interval', 'monthly')
                ->get();

            foreach ($plans as $plan) {
                try {
                    // Store original price for rollback
                    $this->originalPrices[$plan->id] = $plan->price;
                    
                    // Log the price change
                    $message = "Updating price for {$plan->name} plan (ID: {$plan->id}) from \${$plan->price} to \${$newPrice}";
                    Log::info($message);
                    
                    // Log to migration_log if it exists
                    if (Schema::hasTable('migration_log')) {
                        DB::table('migration_log')->insert([
                            'migration' => '2025_09_04_155425_update_core_plan_prices',
                            'message' => $message,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                    
                    // Update the price
                    $plan->price = $newPrice;
                    $plan->save();
                    
                } catch (\Exception $e) {
                    Log::error("Failed to update price for {$plan->name} plan (ID: {$plan->id}) - {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore original prices
        foreach ($this->originalPrices as $planId => $originalPrice) {
            try {
                $plan = Plan::find($planId);
                if ($plan) {
                    $plan->price = $originalPrice;
                    $plan->save();
                    Log::info("Restored original price \${$originalPrice} for plan ID: {$planId}");
                }
            } catch (\Exception $e) {
                Log::error("Failed to restore price for plan ID: {$planId} - {$e->getMessage()}");
            }
        }
    }
};

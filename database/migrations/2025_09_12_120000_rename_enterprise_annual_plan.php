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
     * Store the original plan name for rollback
     */
    protected $originalName;
    
    /**
     * Store the plan ID for rollback
     */
    protected $planId;
    
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            // Find the Enterprise Annual plan
            $plan = Plan::where('name', 'Enterprise Annual')
                    ->where('interval', 'yearly')
                    ->first();
                    
            if ($plan) {
                // Store original data for rollback
                $this->originalName = $plan->name;
                $this->planId = $plan->id;
                
                // Update the name and description
                $plan->name = 'Enterprise Annual (20% Off)';
                $plan->description = 'Get 12,600,000 tokens per year with our standard 20% DISCOUNT! Perfect for large organizations needing the highest level of service.';
                
                // Add discount fields if not already set
                if (!$plan->has_discount) {
                    $plan->regular_price = 1188.00; // $99 * 12 months
                    $plan->discount_percentage = 20;
                    $plan->has_discount = true;
                }
                
                $plan->save();
                
                Log::info("Renamed Enterprise Annual plan (ID: {$plan->id}) to Enterprise Annual (20% Off)");
            } else {
                Log::warning("Enterprise Annual plan not found, could not rename");
            }
        } catch (\Exception $e) {
            Log::error("Error renaming Enterprise Annual plan: {$e->getMessage()}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            // Find the plan by ID if we have it
            if ($this->planId) {
                $plan = Plan::find($this->planId);
                
                if ($plan && $plan->name === 'Enterprise Annual (20% Off)') {
                    $plan->name = $this->originalName ?? 'Enterprise Annual';
                    $plan->description = 'Get 12,600,000 tokens per year at our standard price of $950.40 ($79.20/month)';
                    $plan->save();
                    
                    Log::info("Restored original name for Enterprise Annual plan (ID: {$plan->id})");
                }
            } else {
                // Try to find by name if ID not available
                $plan = Plan::where('name', 'Enterprise Annual (20% Off)')
                        ->where('interval', 'yearly')
                        ->first();
                        
                if ($plan) {
                    $plan->name = 'Enterprise Annual';
                    $plan->description = 'Get 12,600,000 tokens per year at our standard price of $950.40 ($79.20/month)';
                    $plan->save();
                    
                    Log::info("Restored original name for Enterprise Annual plan (ID: {$plan->id})");
                }
            }
        } catch (\Exception $e) {
            Log::error("Error restoring Enterprise Annual plan name: {$e->getMessage()}");
        }
    }
};

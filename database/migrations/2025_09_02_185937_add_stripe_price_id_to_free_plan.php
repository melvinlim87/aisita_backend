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
     * The Stripe price ID to add to the Free plan
     */
    protected $stripePriceId = 'price_1S2rp42MDBrgqcCA8m3VOztS';

    /**
     * Original Stripe price ID (for rollback purposes)
     */
    protected $originalStripePriceId = null;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Find the Free plan
        $freePlan = Plan::where('name', 'Free')->first();

        if ($freePlan) {
            // Store original value for rollback
            $this->originalStripePriceId = $freePlan->stripe_price_id;
            
            // Update the Stripe price ID
            $freePlan->stripe_price_id = $this->stripePriceId;
            $freePlan->save();

            try {
                if (Schema::hasTable('migration_log')) {
                    DB::table('migration_log')->insert([
                        'migration' => '2025_09_02_185937_add_stripe_price_id_to_free_plan',
                        'message' => "Added Stripe price ID '{$this->stripePriceId}' to Free plan",
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }
                
                // Also log to the Laravel log
                Log::info("Added Stripe price ID '{$this->stripePriceId}' to Free plan");
            } catch (\Exception $e) {
                Log::warning("Could not log migration update: {$e->getMessage()}");
            }
        } else {
            Log::warning("Free plan not found");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Find the Free plan
        $freePlan = Plan::where('name', 'Free')->first();
        
        if ($freePlan) {
            // Restore original Stripe price ID
            $freePlan->stripe_price_id = $this->originalStripePriceId;
            $freePlan->save();
            
            Log::info("Restored original Stripe price ID for Free plan during rollback");
        }
    }
};

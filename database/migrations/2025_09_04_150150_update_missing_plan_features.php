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
        // Get all plans with null features
        $plans = Plan::whereNull('features')->get();
        
        foreach ($plans as $plan) {
            $features = [];
            
            // Generate features based on plan name and interval
            if (stripos($plan->name, 'Test Plan') !== false) {
                // Test Plan features
                $features = [
                    'Access to premium AI models',
                    'Limited text analysis',
                    'Testing environment',
                    'Basic support'
                ];
            }
            elseif (stripos($plan->name, 'Basic') !== false) {
                // Basic tier features
                $features = [
                    'Access to regular AI models',
                    'Text-based analysis',
                    'Basic support'
                ];
                
                if (stripos($plan->name, '35% Off') !== false) {
                    $features[] = 'Special promotional pricing';
                    $features[] = '35% discount on annual subscription';
                    $features[] = 'Best value for individuals';
                }
                
                if (stripos($plan->interval, 'one_time') !== false) {
                    $features[] = 'One-time payment (non-recurring)';
                    $features[] = '30% promotional discount';
                    $features[] = 'Pay once, use tokens at your own pace';
                    $features[] = 'No subscription commitment';
                }
                
                if (stripos($plan->interval, 'yearly') !== false) {
                    $features[] = '2 months free compared to monthly plan';
                }
            }
            elseif (stripos($plan->name, 'Pro') !== false) {
                // Pro tier features
                $features = [
                    'Access to all AI models',
                    'Text and image analysis',
                    'Priority support',
                    'Advanced features'
                ];
                
                if (stripos($plan->name, '35% Off') !== false) {
                    $features[] = 'Special promotional pricing';
                    $features[] = '35% discount on annual subscription';
                    $features[] = 'Best value for professionals and teams';
                }
                
                if (stripos($plan->interval, 'one_time') !== false) {
                    $features[] = 'One-time payment (non-recurring)';
                    $features[] = '30% promotional discount';
                    $features[] = 'Pay once, use tokens at your own pace';
                    $features[] = 'No subscription commitment';
                }
                
                if (stripos($plan->interval, 'yearly') !== false) {
                    $features[] = '2 months free compared to monthly plan';
                }
            }
            elseif (stripos($plan->name, 'Enterprise') !== false) {
                // Enterprise tier features
                $features = [
                    'Access to all AI models',
                    'Unlimited text and image analysis',
                    'Priority support with dedicated account manager',
                    'All premium features',
                    'Custom integrations'
                ];
                
                if (stripos($plan->name, '35% Off') !== false) {
                    $features[] = 'Special promotional pricing';
                    $features[] = '35% discount on annual subscription';
                    $features[] = 'Best value for large organizations';
                }
                
                if (stripos($plan->interval, 'one_time') !== false) {
                    $features[] = 'One-time payment (non-recurring)';
                    $features[] = '30% promotional discount';
                    $features[] = 'Pay once, use tokens at your own pace';
                    $features[] = 'No subscription commitment';
                }
                
                if (stripos($plan->interval, 'yearly') !== false) {
                    $features[] = '2 months free compared to monthly plan';
                }
            }
            
            // Update the plan with the generated features
            if (!empty($features)) {
                try {
                    $plan->features = $features;
                    $plan->save();
                    
                    // Log successful update
                    $message = "Updated features for plan: {$plan->name} (ID: {$plan->id})";
                    Log::info($message);
                    
                    // Also log to migration_log table if it exists
                    if (Schema::hasTable('migration_log')) {
                        DB::table('migration_log')->insert([
                            'migration' => '2025_09_04_150150_update_missing_plan_features',
                            'message' => $message,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to update features for plan: {$plan->name} (ID: {$plan->id}) - {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No need to rollback features as they didn't exist before
        Log::info("No rollback necessary for 2025_09_04_150150_update_missing_plan_features");
    }
};

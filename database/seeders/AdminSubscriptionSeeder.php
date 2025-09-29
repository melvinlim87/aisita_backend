<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AdminSubscriptionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Creates special unlimited subscriptions for admin and super_admin users.
     */
    public function run(): void
    {
        try {
            DB::beginTransaction();
            
            // Get the highest tier plan that has premium_models_access
            $premiumPlan = Plan::where('premium_models_access', true)
                ->orderByDesc('tokens_per_cycle')
                ->first();

            // If no premium plan exists, create a special one for admins
            if (!$premiumPlan) {
                $premiumPlan = Plan::create([
                    'name' => 'Admin Special',
                    'description' => 'Special unlimited plan for administrators',
                    'price' => 0.00, // Free for admins
                    'currency' => 'usd',
                    'interval' => 'yearly',
                    'tokens_per_cycle' => 999999999, // Very high number instead of PHP_INT_MAX
                    'features' => json_encode([
                        'Access to all AI models',
                        'Unlimited text and image analysis',
                        'All premium features',
                        'Administrative access'
                    ]),
                    'premium_models_access' => true,
                    'is_active' => true
                ]);
                
                echo "Created special admin plan with ID: {$premiumPlan->id}\n";
                Log::info("Created special admin plan", ['plan_id' => $premiumPlan->id]);
            }

            // Find all admin and super_admin users
            $adminUsers = User::whereHas('role', function($query) {
                $query->whereIn('name', ['admin', 'super_admin']);
            })->get();

            echo "Found {$adminUsers->count()} admin users\n";
            
            // Create or update subscriptions for each admin
            foreach ($adminUsers as $admin) {
                // Check if admin already has an active subscription
                $subscription = Subscription::where('user_id', $admin->id)
                    ->whereIn('status', ['active', 'trialing'])
                    ->first();

                if (!$subscription) {
                    // Create a new unlimited subscription
                    Subscription::create([
                        'user_id' => $admin->id,
                        'plan_id' => $premiumPlan->id,
                        'stripe_subscription_id' => 'admin_' . Str::random(10),
                        'status' => 'active',
                        'next_billing_date' => now()->addYear(), // One year in the future
                        'ends_at' => now()->addYears(5), // 5 years is far enough for testing
                    ]);
                    
                    echo "Created unlimited subscription for admin user ID: {$admin->id}\n";
                    Log::info("Created admin subscription", ['user_id' => $admin->id]);
                } else {
                    // Update the existing subscription to be unlimited
                    $subscription->update([
                        'plan_id' => $premiumPlan->id,
                        'status' => 'active',
                        'next_billing_date' => now()->addYear(),
                        'ends_at' => now()->addYears(5),
                    ]);
                    
                    echo "Updated subscription for admin user ID: {$admin->id}\n";
                    Log::info("Updated admin subscription", ['user_id' => $admin->id]);
                }
            }

            if ($adminUsers->count() === 0) {
                echo "No admin or super_admin users found. Create users with admin roles first.\n";
                Log::warning("No admin users found during AdminSubscriptionSeeder execution");
            } else {
                echo "Admin subscriptions created/updated successfully!\n";
                Log::info("Admin subscriptions seeded successfully");
            }
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            echo "Error: {$e->getMessage()}\n";
            Log::error("AdminSubscriptionSeeder failed", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }
}

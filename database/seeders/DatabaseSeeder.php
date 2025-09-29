<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\AdminSubscriptionSeeder;
use Database\Seeders\ReferralTierSeeder;
use Database\Seeders\SalesMilestoneTierSeeder;
use Database\Seeders\AffiliateRewardsSeeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Seed the roles first
        $this->call(RoleSeeder::class);
        
        // Seed the subscription plans
        $this->call(PlanSeeder::class);
        
        // User::factory(10)->create();

        // Create a regular user (role_id 1)
        \App\Models\User::factory()->create([
            'name' => 'Regular User',
            'email' => 'user@decyphers.com',
            'role_id' => 1, // Regular user role
        ]);
        
        // Create an admin user (role_id 2)
        \App\Models\User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@decyphers.com',
            'role_id' => 2, // Admin role
        ]);
        
        // Create a super admin user (role_id 3)
        \App\Models\User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@decyphers.com',
            'role_id' => 3, // Super admin role
        ]);
        
        // Create unlimited subscriptions for admin users
        $this->call(AdminSubscriptionSeeder::class);
        
        // Seed referral tiers
        $this->call(ReferralTierSeeder::class);
        
        // Seed sales milestone tiers
        $this->call(SalesMilestoneTierSeeder::class);

        // Seed sample affiliate rewards for testing the admin UI
        $this->call(AffiliateRewardsSeeder::class);
    }
}

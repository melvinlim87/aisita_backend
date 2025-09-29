<?php

namespace Database\Seeders;

use App\Models\SalesMilestoneTier;
use Illuminate\Database\Seeder;

class SalesMilestoneTierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Check if there are any milestone awards using these tiers
        if (\DB::table('affiliate_milestone_awards')->count() === 0) {
            // If safe, delete existing tiers
            SalesMilestoneTier::query()->delete();
        } else {
            // If there are awards, just update existing tiers
            return;
        }

        // 10 Sales - Sales Builder
        SalesMilestoneTier::create([
            'name' => 'Sales Builder',
            'required_sales' => 10,
            'badge' => 'Sales Builder',
            'subscription_reward' => 'basic',
            'subscription_months' => 1,
            'cash_bonus' => null,
            'has_physical_plaque' => false,
            'perks' => 'First step into Pro tier'
        ]);

        // 20 Sales - Starter Affiliate
        SalesMilestoneTier::create([
            'name' => 'Starter Affiliate',
            'required_sales' => 20,
            'badge' => 'Starter Affiliate',
            'subscription_reward' => 'pro',
            'subscription_months' => 1,
            'cash_bonus' => null,
            'has_physical_plaque' => false,
            'perks' => 'Listed on leaderboard, training access'
        ]);

        // 40 Sales - Rising Star
        SalesMilestoneTier::create([
            'name' => 'Rising Star',
            'required_sales' => 40,
            'badge' => 'Rising Star',
            'subscription_reward' => null,
            'subscription_months' => 0,
            'cash_bonus' => 50.00,
            'has_physical_plaque' => false,
            'perks' => 'Profile highlight, priority support'
        ]);

        // 60 Sales - Elite Affiliate
        SalesMilestoneTier::create([
            'name' => 'Elite Affiliate',
            'required_sales' => 60,
            'badge' => 'Elite Affiliate',
            'subscription_reward' => 'enterprise',
            'subscription_months' => 2,
            'cash_bonus' => null,
            'has_physical_plaque' => false,
            'perks' => 'Early tool access, private Elite chat'
        ]);

        // 100 Sales - Affiliate Excellence Award
        SalesMilestoneTier::create([
            'name' => 'Affiliate Excellence Award',
            'required_sales' => 100,
            'badge' => 'Affiliate Excellence',
            'subscription_reward' => null,
            'subscription_months' => 0,
            'cash_bonus' => 150.00,
            'has_physical_plaque' => true,
            'perks' => 'VIP hotline, newsletter recognition'
        ]);

        // 150 Sales - VIP Affiliate
        SalesMilestoneTier::create([
            'name' => 'VIP Affiliate',
            'required_sales' => 150,
            'badge' => 'VIP Affiliate',
            'subscription_reward' => null,
            'subscription_months' => 0,
            'cash_bonus' => 300.00,
            'has_physical_plaque' => true,
            'perks' => 'Lifetime 50% discount on plans, strategy calls'
        ]);

        // 200 Sales - Legend Affiliate
        SalesMilestoneTier::create([
            'name' => 'Legend Affiliate',
            'required_sales' => 200,
            'badge' => 'Legend Affiliate',
            'subscription_reward' => 'enterprise',
            'subscription_months' => 6,
            'cash_bonus' => null,
            'has_physical_plaque' => true,
            'perks' => 'Featured case study, invite to Mastermind, Merchandise, Pinnacle Award'
        ]);
    }
}

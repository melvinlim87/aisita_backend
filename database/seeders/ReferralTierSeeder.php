<?php

namespace Database\Seeders;

use App\Models\ReferralTier;
use Illuminate\Database\Seeder;

class ReferralTierSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing tiers
        ReferralTier::truncate();

        // Bronze-Silver Tier: 1-99 referrals
        ReferralTier::create([
            'name' => 'Bronze-Silver Tier',
            'min_referrals' => 1,
            'max_referrals' => 99,
            'referrer_tokens' => 20000, // 5 Free Analyses (20,000 credits)
            'referee_tokens' => 20000,  // 5 Free Analyses (20,000 credits)
            'badge' => 'Bronze-Silver',
            'subscription_reward' => null,
            'subscription_months' => 0
        ]);

        // Gold Partner: 100-299 referrals
        ReferralTier::create([
            'name' => 'Gold Partner',
            'min_referrals' => 100,
            'max_referrals' => 299,
            'referrer_tokens' => 40000, // 10 Free Analyses (40,000 credits)
            'referee_tokens' => 40000,  // 10 Free Analyses (40,000 credits)
            'badge' => 'Gold',
            'subscription_reward' => 'basic', // 1 Month Basic Plan
            'subscription_months' => 1
        ]);

        // Platinum Partner: 300-499 referrals
        ReferralTier::create([
            'name' => 'Platinum Partner',
            'min_referrals' => 300,
            'max_referrals' => 499,
            'referrer_tokens' => 40000, // 10 Free Analyses (40,000 credits)
            'referee_tokens' => 40000,  // 10 Free Analyses (40,000 credits)
            'badge' => 'Platinum',
            'subscription_reward' => 'pro', // 1 Month Pro Plan
            'subscription_months' => 1
        ]);

        // Elite Partner: 500+ referrals
        ReferralTier::create([
            'name' => 'Elite Partner',
            'min_referrals' => 500,
            'max_referrals' => null,  // No upper limit
            'referrer_tokens' => 60000, // 15 Free Analyses (60,000 credits)
            'referee_tokens' => 60000,  // 15 Free Analyses (60,000 credits)
            'badge' => 'Elite',
            'subscription_reward' => 'enterprise', // 1 Month Enterprise Plan
            'subscription_months' => 1
        ]);
    }
}

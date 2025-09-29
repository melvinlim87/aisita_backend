<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Truncate the table first to avoid duplicates
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('plans')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        
        // Create Free plan
        Plan::create([
            'name' => 'Free',
            'description' => 'Free plan with limited features and 12,000 tokens',
            'price' => 0.00,
            'currency' => 'usd',
            'interval' => 'monthly',
            'tokens_per_cycle' => 12000,
            'features' => [
                'Limited access to AI models',
                'Basic text analysis',
                'Community support'
            ],
            'premium_models_access' => false,
            'stripe_price_id' => 'price_1S2rp42MDBrgqcCA8m3VOztS',
            'is_active' => true
        ]);
        
        // Create basic monthly plans
        Plan::create([
            'name' => 'Basic',
            'description' => 'Basic plan with access to non-premium models and 100,000 tokens per month',
            'price' => 19.00,
            'currency' => 'usd',
            'interval' => 'monthly',
            'tokens_per_cycle' => 100000,
            'features' => [
                'Access to regular AI models',
                'Text-based analysis',
                'Basic support'
            ],
            'premium_models_access' => false,
            'stripe_price_id' => 'price_1S2sxC2MDBrgqcCAPtRcfmLE',
            'is_active' => true
        ]);

        Plan::create([
            'name' => 'Pro',
            'description' => 'Pro plan with access to all models and 350,000 tokens per month',
            'price' => 49.00,
            'currency' => 'usd',
            'interval' => 'monthly',
            'tokens_per_cycle' => 350000,
            'features' => [
                'Access to all AI models',
                'Text and image analysis',
                'Priority support',
                'Advanced features'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1S2sxj2MDBrgqcCAmE4CiNVz',
            'is_active' => true
        ]);

        Plan::create([
            'name' => 'Enterprise',
            'description' => 'Enterprise plan with access to all models and 1,050,000 tokens per month',
            'price' => 99.00,
            'currency' => 'usd',
            'interval' => 'monthly',
            'tokens_per_cycle' => 1050000,
            'features' => [
                'Access to all AI models',
                'Unlimited text and image analysis',
                'Priority support with dedicated account manager',
                'All premium features',
                'Custom integrations'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1S2syQ2MDBrgqcCAdyLgRQPj',
            'is_active' => true
        ]);
        
        // Create one-time payment plans (30% discount)
        Plan::create([
            'name' => 'Basic One-Time',
            'description' => 'ONE-TIME PAYMENT of $13.30 for 100,000 tokens. This is not a recurring subscription - pay once and use your tokens when you need them. Special 30% off promotion.',
            'price' => 13.30,
            'currency' => 'usd',
            'interval' => 'one_time',
            'tokens_per_cycle' => 100000,
            'support_available' => true,
            'features' => [
                'Access to regular AI models',
                'Text-based analysis',
                'Basic support',
                'One-time payment (non-recurring)',
                '30% promotional discount',
                'Pay once, use tokens at your own pace',
                'No subscription commitment'
            ],
            'premium_models_access' => false,
            'stripe_price_id' => 'price_1S2tP62MDBrgqcCAtJo2jajj',
            'is_active' => true
        ]);
        
        Plan::create([
            'name' => 'Pro One-Time',
            'description' => 'ONE-TIME PAYMENT of $34.30 for 350,000 tokens. This is not a recurring subscription - pay once and use your tokens when you need them. Special 30% off promotion.',
            'price' => 34.30,
            'currency' => 'usd',
            'interval' => 'one_time',
            'tokens_per_cycle' => 350000,
            'support_available' => true,
            'features' => [
                'Access to all AI models',
                'Text and image analysis',
                'Priority support',
                'Advanced features',
                'One-time payment (non-recurring)',
                '30% promotional discount',
                'Pay once, use tokens at your own pace',
                'No subscription commitment'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1S2tPk2MDBrgqcCA2C0438jP',
            'is_active' => true
        ]);
        
        Plan::create([
            'name' => 'Enterprise One-Time',
            'description' => 'ONE-TIME PAYMENT of $69.30 for 1,050,000 tokens. This is not a recurring subscription - pay once and use your tokens when you need them. Special 30% off promotion.',
            'price' => 69.30,
            'currency' => 'usd',
            'interval' => 'one_time',
            'tokens_per_cycle' => 1050000,
            'support_available' => true,
            'features' => [
                'Access to all AI models',
                'Unlimited text and image analysis',
                'Priority support with dedicated account manager',
                'All premium features',
                'Custom integrations',
                'One-time payment (non-recurring)',
                '30% promotional discount',
                'Pay once, use tokens at your own pace',
                'No subscription commitment'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1S2tQD2MDBrgqcCANeAvdSBS',
            'is_active' => true
        ]);
        
        // Create annual one-time payment plans (30% discount)
        Plan::create([
            'name' => 'Basic Annual One-Time',
            'description' => 'ONE-TIME PAYMENT of $159.60 for 1,200,000 tokens. This is not a recurring subscription - pay once and use your tokens throughout the year. Special 30% first-year discount.',
            'price' => 159.60,
            'currency' => 'usd',
            'interval' => 'one_time',
            'tokens_per_cycle' => 1200000,
            'support_available' => true,
            'features' => [
                'Access to regular AI models',
                'Text-based analysis',
                'Basic support',
                'One-time payment (non-recurring)',
                '30% promotional discount',
                'Pay once, use tokens at your own pace',
                'No subscription commitment'
            ],
            'premium_models_access' => false,
            'stripe_price_id' => 'price_1S2tQx2MDBrgqcCAtMOL4cta',
            'is_active' => true
        ]);
        
        Plan::create([
            'name' => 'Pro Annual One-Time',
            'description' => 'ONE-TIME PAYMENT of $411.60 for 4,200,000 tokens. This is not a recurring subscription - pay once and use your tokens throughout the year. Special 30% first-year discount.',
            'price' => 411.60,
            'currency' => 'usd',
            'interval' => 'one_time',
            'tokens_per_cycle' => 4200000,
            'support_available' => true,
            'features' => [
                'Access to all AI models',
                'Text and image analysis',
                'Priority support',
                'Advanced features',
                'One-time payment (non-recurring)',
                '30% promotional discount',
                'Pay once, use tokens at your own pace',
                'No subscription commitment'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1S2tRR2MDBrgqcCA53uaBa3x',
            'is_active' => true
        ]);
        
        Plan::create([
            'name' => 'Enterprise Annual One-Time',
            'description' => 'ONE-TIME PAYMENT of $831.60 for 12,600,000 tokens. This is not a recurring subscription - pay once and use your tokens throughout the year. Special 30% first-year discount.',
            'price' => 831.60,
            'currency' => 'usd',
            'interval' => 'one_time',
            'tokens_per_cycle' => 12600000,
            'support_available' => true,
            'features' => [
                'Access to all AI models',
                'Unlimited text and image analysis',
                'Priority support with dedicated account manager',
                'All premium features',
                'Custom integrations',
                'One-time payment (non-recurring)',
                '30% promotional discount',
                'Pay once, use tokens at your own pace',
                'No subscription commitment'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1S2tS72MDBrgqcCAm0EQ2JPC',
            'is_active' => true
        ]);

        // Create 20% discount annual plans
        Plan::create([
            'name' => 'Basic Annual (20% Off)',
            'description' => 'Get 1,200,000 tokens per year with our standard 20% DISCOUNT! Perfect for individuals who need reliable access to AI capabilities.',
            'price' => 182.40,
            'regular_price' => 228.00,
            'currency' => 'usd',
            'interval' => 'yearly',
            'tokens_per_cycle' => 1200000,
            'discount_percentage' => 20,
            'has_discount' => true,
            'support_available' => true,
            'features' => [
                'Access to regular AI models',
                'Text-based analysis',
                'Basic support',
                '20% discount on annual subscription',
                '2 months free compared to monthly plan'
            ],
            'premium_models_access' => false,
            'stripe_price_id' => 'price_1RkiGS2MDBrgqcCAPu1ILnrx',
            'is_active' => true
        ]);

        Plan::create([
            'name' => 'Pro Annual (20% Off)',
            'description' => 'Access 4,200,000 tokens per year with our standard 20% DISCOUNT! Ideal for businesses and advanced users.',
            'price' => 470.40,
            'regular_price' => 588.00,
            'currency' => 'usd',
            'interval' => 'yearly',
            'tokens_per_cycle' => 4200000,
            'discount_percentage' => 20,
            'has_discount' => true,
            'support_available' => true,
            'features' => [
                'Access to all AI models',
                'Text and image analysis',
                'Priority support',
                'Advanced features',
                '20% discount on annual subscription',
                '2 months free compared to monthly plan'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1RkiH32MDBrgqcCAyJTES8hR',
            'is_active' => true
        ]);
        
        Plan::create([
            'name' => 'Enterprise Annual (20% Off)',
            'description' => 'Get 12,600,000 tokens per year with our standard 20% DISCOUNT! Perfect for large organizations needing the highest level of service.',
            'price' => 950.40,
            'regular_price' => 1188.00,
            'currency' => 'usd',
            'interval' => 'yearly',
            'tokens_per_cycle' => 12600000,
            'discount_percentage' => 20,
            'has_discount' => true,
            'support_available' => true,
            'features' => [
                'Access to all AI models',
                'Unlimited text and image analysis',
                'Priority support with dedicated account manager',
                'All premium features',
                'Custom integrations',
                '20% discount on annual subscription',
                '2 months free compared to monthly plan'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1RkiHb2MDBrgqcCAHx1ADJn9',
            'is_active' => true
        ]);

        // Create 35% discount annual plans
        Plan::create([
            'name' => 'Basic Annual (35% Off)',
            'description' => 'Get 1,200,000 tokens per year with our SPECIAL 35% DISCOUNT! Perfect for individuals who need reliable access to AI capabilities at a budget-friendly price.',
            'price' => 148.20,
            'regular_price' => 238.80,
            'currency' => 'usd',
            'interval' => 'yearly',
            'tokens_per_cycle' => 1200000,
            'discount_percentage' => 35,
            'has_discount' => true,
            'features' => [
                'Access to regular AI models',
                'Text-based analysis',
                'Basic support',
                '35% discount on annual subscription'
            ],
            'premium_models_access' => false,
            'stripe_price_id' => 'price_1S2tkv2MDBrgqcCAvbZHCDn0',
            'is_active' => true
        ]);

        Plan::create([
            'name' => 'Pro Annual (35% Off)',
            'description' => 'Access 4,200,000 tokens per year with our SPECIAL 35% DISCOUNT! Ideal for businesses and advanced users who need premium features and higher capacity.',
            'price' => 382.20,
            'regular_price' => 618.00,
            'currency' => 'usd',
            'interval' => 'yearly',
            'tokens_per_cycle' => 4200000,
            'discount_percentage' => 35,
            'has_discount' => true,
            'features' => [
                'Access to all AI models',
                'Text and image analysis',
                'Priority support',
                'Advanced features',
                '35% discount on annual subscription'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1S2tlj2MDBrgqcCANdEK5tz2',
            'is_active' => true
        ]);
        
        Plan::create([
            'name' => 'Enterprise Annual (35% Off)',
            'description' => 'Unlock 12,600,000 tokens per year with our SPECIAL 35% DISCOUNT! Perfect for large teams and organizations needing the highest level of service and capacity.',
            'price' => 772.20,
            'regular_price' => 1248.00,
            'currency' => 'usd',
            'interval' => 'yearly',
            'tokens_per_cycle' => 12600000,
            'discount_percentage' => 35,
            'has_discount' => true,
            'features' => [
                'Access to all AI models',
                'Unlimited text and image analysis',
                'Priority support with dedicated account manager',
                'All premium features',
                'Custom integrations',
                '35% discount on annual subscription'
            ],
            'premium_models_access' => true,
            'stripe_price_id' => 'price_1S2tmY2MDBrgqcCAY8NWUikU',
            'is_active' => true
        ]);
    }
}

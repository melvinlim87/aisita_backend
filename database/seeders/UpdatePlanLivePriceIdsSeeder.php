<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdatePlanLivePriceIdsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Map each plan name to its LIVE Stripe Price ID.
        // Fill in the live price IDs below. Leaving a value empty will skip that plan.
        $livePriceMap = [
            // Monthly subscription plans
            'Free' => 'price_1SAoaeRqFaHWPtRl3koLjtaj',
            'Basic' => 'price_1SAoabRqFaHWPtRlLgaRctr7',
            'Pro' => 'price_1SAoaYRqFaHWPtRlHN8x37gb',
            'Enterprise' => 'price_1SAoZzRqFaHWPtRlK71EHuRi',

            // One-time token packages
            'Basic One-Time' => 'price_1SAoZoRqFaHWPtRlordSjKdg',
            'Pro One-Time' => 'price_1SAoZmRqFaHWPtRlFO177Ejf',
            'Enterprise One-Time' => 'price_1SAoZkRqFaHWPtRlCNCUUFwp',

            // Annual one-time token packages
            'Basic Annual One-Time' => 'price_1SAoZcRqFaHWPtRlSF9rLPww',
            'Pro Annual One-Time' => 'price_1SAoZZRqFaHWPtRlEADzd4rS',
            'Enterprise Annual One-Time' => 'price_1SAoZFRqFaHWPtRllK0PuDkR',

            // Annual subscription plans (20% off)
            'Basic Annual (20% Off)' => 'price_1SAoajRqFaHWPtRlktgmF311',
            'Pro Annual (20% Off)' => 'price_1SAoahRqFaHWPtRl5HDD8fqV',
            'Enterprise Annual (20% Off)' => 'price_1SAoagRqFaHWPtRlF65Rt4ep',

            // Annual subscription plans (35% off)
            'Basic Annual (35% Off)' => 'price_1SAoZzRqFaHWPtRlK71EHuRi',
            'Pro Annual (35% Off)' => 'price_1SAoZBRqFaHWPtRlnUkTeaPr',
            'Enterprise Annual (35% Off)' => 'price_1SAoJmRqFaHWPtRl1EOGVyFk',
        ];

        $updated = 0;
        $skipped = 0;
        foreach ($livePriceMap as $planName => $livePriceId) {
            if (!is_string($livePriceId) || $livePriceId === '') {
                $skipped++;
                continue;
            }

            $count = DB::table('plans')
                ->where('name', $planName)
                ->update(['stripe_price_id_live' => $livePriceId]);

            if ($count > 0) {
                $updated += $count;
                $this->command?->info("Updated {$count} row(s) for plan '{$planName}'");
            } else {
                $this->command?->warn("No matching plan found for '{$planName}'");
            }
        }

        $this->command?->line("\nSummary:");
        $this->command?->line("- Updated rows: {$updated}");
        $this->command?->line("- Skipped (no value provided): {$skipped}");
        $this->command?->line("Done.");
    }
}

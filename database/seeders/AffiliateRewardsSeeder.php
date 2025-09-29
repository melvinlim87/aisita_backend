<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\User;

class AffiliateRewardsSeeder extends Seeder
{
    /**
     * Seed sample affiliate rewards so the admin UI `/affiliate/rewards` can be tested.
     */
    public function run(): void
    {
        // Try to find a few existing users from DatabaseSeeder
        $regular = User::where('email', 'user@decyphers.com')->first();
        $admin = User::where('email', 'admin@decyphers.com')->first();
        $super = User::where('email', 'superadmin@decyphers.com')->first();

        // Fallback: create a test user if none of the above exist
        if (!$regular) {
            $regular = User::factory()->create([
                'name' => 'Affiliate Tester',
                'email' => 'affiliate.tester@decyphers.com',
            ]);
        }

        // Minimal sample rewards; plan_id is optional (nullable), so we'll set null for simplicity
        $now = now();

        $rows = [
            [
                'user_id' => $regular->id,
                'reward_type' => 'subscription',
                'value' => json_encode(['plan_type' => 'pro', 'months' => 1]),
                'plan_id' => null,
                'status' => 'awarded',
                'notes' => 'Seeded: Free 1-month Pro for hitting Starter Affiliate',
                'fulfilled_at' => null,
                'created_at' => $now->copy()->subDays(5),
                'updated_at' => $now->copy()->subDays(5),
            ],
            [
                'user_id' => $regular->id,
                'reward_type' => 'cash',
                'value' => 50.00, // Cash bonus stored as numeric/text per migration comment
                'plan_id' => null,
                'status' => 'pending',
                'notes' => 'Seeded: $50 cash bonus pending processing',
                'fulfilled_at' => null,
                'created_at' => $now->copy()->subDays(3),
                'updated_at' => $now->copy()->subDays(3),
            ],
            [
                'user_id' => ($admin?->id ?? $regular->id),
                'reward_type' => 'plaque',
                'value' => json_encode(['tier' => 'Sales Builder', 'sales_count' => 10, 'achievement' => 'Bronze']),
                'plan_id' => null,
                'status' => 'fulfilled',
                'notes' => 'Seeded: Physical plaque shipped',
                'fulfilled_at' => $now->copy()->subDay(),
                'created_at' => $now->copy()->subDays(10),
                'updated_at' => $now->copy()->subDay(),
            ],
        ];

        DB::table('affiliate_rewards')->insert($rows);
    }
}

<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserBadge;
use App\Models\Subscription;
use App\Models\SalesMilestoneTier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AffiliateService
{
    /**
     * @var BadgeService
     */
    protected $badgeService;

    /**
     * AffiliateService constructor.
     *
     * @param BadgeService $badgeService
     */
    public function __construct(BadgeService $badgeService)
    {
        $this->badgeService = $badgeService;
    }

    /**
     * Track a new paid subscription referred by an affiliate
     * 
     * @param User $affiliate
     * @param User $customer
     * @param Subscription $subscription
     * @param float|null $amount Optional explicit amount for the sale
     * @return array
     */
    public function trackAffiliateSale(User $affiliate, User $customer, Subscription $subscription, ?float $amount = null): array
    {
        try {
            DB::beginTransaction();
            
            // Determine final amount for this sale
            // Priority: explicit $amount -> subscription amount -> 0.00
            $finalAmount = $amount ?? $subscription->amount ?? 0.00;
            
            // Record the affiliate sale in the database
            $sale = DB::table('affiliate_sales')->insertGetId([
                'affiliate_id' => $affiliate->id,
                'customer_id' => $customer->id,
                'subscription_id' => $subscription->id,
                'plan_id' => $subscription->plan_id,
                'amount' => $finalAmount,
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            // Update the affiliate's sales count
            $affiliate->sales_count = ($affiliate->sales_count ?? 0) + 1;
            $affiliate->save();
            
            // Check if the affiliate reached a new milestone
            $result = $this->checkAndAwardMilestone($affiliate);
            
            DB::commit();
            
            return [
                'success' => true,
                'message' => 'Affiliate sale tracked successfully',
                'sale_id' => $sale,
                'milestone_reached' => $result['milestone_reached'],
                'rewards' => $result['rewards'] ?? null
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error tracking affiliate sale: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Error tracking affiliate sale: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Check if a user has reached a new milestone and award accordingly
     * 
     * @param User $user
     * @return array
     */
    public function checkAndAwardMilestone(User $user): array
    {
        $salesCount = $user->sales_count ?? 0;
        
        if ($salesCount <= 0) {
            return [
                'milestone_reached' => false,
                'message' => 'No sales recorded yet'
            ];
        }
        
        // Get the milestone tier for the current sales count
        $tier = SalesMilestoneTier::getTierBySalesCount($salesCount);
        
        if (!$tier) {
            return [
                'milestone_reached' => false,
                'message' => 'No milestone tier reached yet'
            ];
        }
        
        // Check if this milestone has already been awarded
        $existingAward = DB::table('affiliate_milestone_awards')
            ->where('user_id', $user->id)
            ->where('tier_id', $tier->id)
            ->first();
            
        if ($existingAward) {
            return [
                'milestone_reached' => false,
                'message' => 'This milestone has already been awarded',
                'tier' => $tier->name
            ];
        }
        
        // Record the milestone award
        $awardId = DB::table('affiliate_milestone_awards')->insertGetId([
            'user_id' => $user->id,
            'tier_id' => $tier->id,
            'sales_count' => $salesCount,
            'awarded_at' => now(),
            'created_at' => now(),
            'updated_at' => now()
        ]);
        
        // Prepare rewards
        $rewards = [];
        
        // Award badge
        $badge = $this->awardAffiliateBadge($user, $tier);
        $rewards['badge'] = $badge ? $tier->badge : null;
        
        // Award subscription if applicable
        if ($tier->subscription_reward) {
            $subscriptionAwarded = $this->awardSubscription(
                $user, 
                $tier->subscription_reward, 
                $tier->subscription_months
            );
            $rewards['subscription'] = $subscriptionAwarded ? [
                'type' => $tier->subscription_reward,
                'months' => $tier->subscription_months
            ] : null;
        }
        
        // Award cash bonus if applicable
        if ($tier->cash_bonus > 0) {
            $bonusAwarded = $this->awardCashBonus($user, $tier->cash_bonus);
            $rewards['cash_bonus'] = $bonusAwarded ? $tier->cash_bonus : null;
        }
        
        // Record plaque request if applicable
        if ($tier->has_physical_plaque) {
            $plaqueRequested = $this->requestPhysicalPlaque($user, $tier);
            $rewards['physical_plaque'] = $plaqueRequested;
        }
        
        return [
            'milestone_reached' => true,
            'tier' => $tier->name,
            'badge' => $tier->badge,
            'rewards' => $rewards
        ];
    }
    
    /**
     * Award an affiliate badge to a user based on their tier
     * 
     * @param User $user
     * @param SalesMilestoneTier $tier
     * @return bool
     */
    private function awardAffiliateBadge(User $user, SalesMilestoneTier $tier): bool
    {
        try {
            UserBadge::updateOrCreate(
                ['user_id' => $user->id, 'badge_type' => 'affiliate'],
                [
                    'badge_level' => $tier->badge,
                    'description' => "Achieved {$tier->name} with {$user->sales_count} sales",
                    'awarded_at' => now()
                ]
            );
            
            return true;
        } catch (\Exception $e) {
            Log::error("Error awarding affiliate badge: {$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * Award a subscription to a user
     * 
     * @param User $user
     * @param string $planType
     * @param int $months
     * @return bool
     */
    private function awardSubscription(User $user, string $planType, int $months): bool
    {
        try {
            // Find the plan ID for the milestone reward
            $planId = null;
            switch ($planType) {
                case 'basic':
                    $planId = config('subscription.plans.basic');
                    break;
                case 'pro':
                    $planId = config('subscription.plans.pro');
                    break;
                case 'enterprise':
                    $planId = config('subscription.plans.enterprise');
                    break;
            }
            
            if (!$planId) {
                Log::error("Invalid plan type: {$planType}");
                return false;
            }
            
            // Create or extend subscription (simplified)
            // In a real implementation, you would integrate with your subscription service
            // For now, we'll just log that a reward should be given
            Log::info("Milestone reward: {$months} month(s) of {$planType} plan awarded to affiliate {$user->id}");
            
            // Record in affiliate_rewards table
            DB::table('affiliate_rewards')->insert([
                'user_id' => $user->id,
                'reward_type' => 'subscription',
                'plan_id' => $planId,
                'value' => json_encode([
                    'plan_type' => $planType,
                    'months' => $months
                ]),
                'status' => 'awarded',
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            return true;
        } catch (\Exception $e) {
            Log::error("Error awarding milestone subscription: {$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * Award a cash bonus to a user
     * 
     * @param User $user
     * @param float $amount
     * @return bool
     */
    private function awardCashBonus(User $user, float $amount): bool
    {
        try {
            // Record the cash bonus in the database
            DB::table('affiliate_rewards')->insert([
                'user_id' => $user->id,
                'reward_type' => 'cash',
                'value' => $amount,
                'status' => 'pending', // Will require admin approval/processing
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            Log::info("Cash bonus of \${$amount} awarded to affiliate {$user->id}");
            
            return true;
        } catch (\Exception $e) {
            Log::error("Error awarding cash bonus: {$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * Request a physical plaque for a user
     * 
     * @param User $user
     * @param SalesMilestoneTier $tier
     * @return bool
     */
    private function requestPhysicalPlaque(User $user, SalesMilestoneTier $tier): bool
    {
        try {
            // Record the plaque request in the database
            DB::table('affiliate_rewards')->insert([
                'user_id' => $user->id,
                'reward_type' => 'plaque',
                'value' => json_encode([
                    'tier' => $tier->name,
                    'sales_count' => $user->sales_count,
                    'achievement' => $tier->badge
                ]),
                'status' => 'pending', // Will require fulfillment
                'created_at' => now(),
                'updated_at' => now()
            ]);
            
            Log::info("Physical plaque for {$tier->name} requested for affiliate {$user->id}");
            
            return true;
        } catch (\Exception $e) {
            Log::error("Error requesting physical plaque: {$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * Get affiliate status and progress for a user
     * 
     * @param User $user
     * @return array
     */
    public function getAffiliateStatus(User $user): array
    {
        try {
            // Get sales count
            $salesCount = $user->sales_count ?? 0;
            
            // Get current tier
            $currentTier = SalesMilestoneTier::getTierBySalesCount($salesCount);
            
            // Get next tier
            $nextTier = SalesMilestoneTier::getNextTier($salesCount);
            
            // Get affiliate badge
            $badge = $user->badges()->where('badge_type', 'affiliate')->first();
            
            // Get earned rewards
            $rewards = DB::table('affiliate_rewards')
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();
                
            // Get milestone awards
            $milestones = DB::table('affiliate_milestone_awards')
                ->where('user_id', $user->id)
                ->join('sales_milestone_tiers', 'affiliate_milestone_awards.tier_id', '=', 'sales_milestone_tiers.id')
                ->select(
                    'affiliate_milestone_awards.*',
                    'sales_milestone_tiers.name',
                    'sales_milestone_tiers.badge',
                    'sales_milestone_tiers.required_sales'
                )
                ->orderBy('affiliate_milestone_awards.sales_count', 'desc')
                ->get();
            
            return [
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'sales_count' => $salesCount,
                    'current_tier' => $currentTier ? [
                        'id' => $currentTier->id,
                        'name' => $currentTier->name,
                        'badge' => $currentTier->badge,
                        'required_sales' => $currentTier->required_sales,
                        'perks' => $currentTier->perks
                    ] : null,
                    'next_tier' => $nextTier ? [
                        'id' => $nextTier->id,
                        'name' => $nextTier->name,
                        'badge' => $nextTier->badge,
                        'required_sales' => $nextTier->required_sales,
                        'sales_needed' => $nextTier->required_sales - $salesCount,
                        'perks' => $nextTier->perks
                    ] : null,
                    'badge' => $badge ? [
                        'level' => $badge->badge_level,
                        'description' => $badge->description,
                        'awarded_at' => $badge->awarded_at->format('Y-m-d H:i:s'),
                    ] : null,
                    'rewards' => $rewards,
                    'milestones' => $milestones
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Error getting affiliate status: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Error getting affiliate status: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get the affiliate leaderboard
     * 
     * @param int $limit
     * @param string $period month, year, all
     * @return array
     */
    public function getAffiliateLeaderboard(int $limit = 10, string $period = 'all'): array
    {
        try {
            $query = DB::table('users')
                ->select('users.id', 'users.name', 'users.email', 'users.sales_count', 'user_badges.badge_level')
                ->leftJoin('user_badges', function($join) {
                    $join->on('users.id', '=', 'user_badges.user_id')
                         ->where('user_badges.badge_type', '=', 'affiliate');
                })
                ->where('users.sales_count', '>', 0)
                ->orderBy('users.sales_count', 'desc');
                
            // Filter by period if specified
            if ($period === 'month') {
                $query->join('affiliate_sales', 'users.id', '=', 'affiliate_sales.affiliate_id')
                      ->where('affiliate_sales.created_at', '>=', now()->startOfMonth())
                      ->groupBy('users.id', 'users.name', 'users.email', 'users.sales_count', 'user_badges.badge_level');
            } elseif ($period === 'year') {
                $query->join('affiliate_sales', 'users.id', '=', 'affiliate_sales.affiliate_id')
                      ->where('affiliate_sales.created_at', '>=', now()->startOfYear())
                      ->groupBy('users.id', 'users.name', 'users.email', 'users.sales_count', 'user_badges.badge_level');
            }
            
            $leaderboard = $query->limit($limit)->get();
            
            return [
                'success' => true,
                'data' => [
                    'period' => $period,
                    'leaderboard' => $leaderboard
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Error getting affiliate leaderboard: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Error getting affiliate leaderboard: ' . $e->getMessage()
            ];
        }
    }
}

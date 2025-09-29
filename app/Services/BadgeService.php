<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserBadge;
use App\Models\ReferralTier;
use Illuminate\Support\Facades\Log;

class BadgeService
{
    /**
     * Update or create a referral badge for a user based on their referral count
     *
     * @param User $user
     * @return UserBadge|null
     */
    public function updateReferralBadge(User $user): ?UserBadge
    {
        try {
            $referralCount = $user->referral_count;
            
            // Get the current tier for the user's referral count
            $tier = ReferralTier::getTierByReferralCount($referralCount);
            
            if (!$tier) {
                // No tier found for this referral count
                Log::warning("No referral tier found for count: {$referralCount}");
                return null;
            }
            
            // Update or create the badge
            $badge = UserBadge::updateOrCreate(
                ['user_id' => $user->id, 'badge_type' => 'referral'],
                [
                    'badge_level' => $tier->badge,
                    'description' => "Achieved {$tier->name} with {$referralCount} referrals",
                    'awarded_at' => now()
                ]
            );
            
            Log::info("Updated badge for user {$user->id}: {$tier->badge}");
            
            return $badge;
        } catch (\Exception $e) {
            Log::error("Error updating referral badge: {$e->getMessage()}");
            return null;
        }
    }
    
    /**
     * Check and update user badges based on milestone events
     *
     * @param User $user
     * @return void
     */
    public function checkAllBadges(User $user): void
    {
        // Check and update referral badge
        $this->updateReferralBadge($user);
        
        // Future badge checks can be added here
        // e.g., purchases, analysis usage, etc.
    }
    
    /**
     * Get all badges for a user
     *
     * @param User $user
     * @return array
     */
    public function getUserBadges(User $user): array
    {
        try {
            $badges = $user->badges()->get();
            
            return [
                'success' => true,
                'badges' => $badges,
                'count' => $badges->count()
            ];
        } catch (\Exception $e) {
            Log::error("Error getting user badges: {$e->getMessage()}");
            
            return [
                'success' => false,
                'message' => "Error retrieving badges: {$e->getMessage()}"
            ];
        }
    }
}

<?php

namespace App\Services;

use App\Models\User;
use App\Models\Referral;
use App\Models\ReferralTier;
use App\Models\UserBadge;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ReferralService
{
    /**
     * Generate a unique referral code for a user
     *
     * @param User $user
     * @return string
     */
    public function generateReferralCode(User $user): string
    {
        // Check if user already has a referral code
        if ($user->referral_code) {
            return $user->referral_code;
        }
        
        // Get the user's first name
        $firstName = explode(' ', $user->name)[0] ?? 'user';
        $firstName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $firstName));
        
        // Generate a random string for uniqueness
        $randomChars = strtolower(Str::random(4));
        
        // Create the code in the format firstname_randomchar
        $code = "{$firstName}_{$randomChars}";
        
        // Make sure the code is unique
        while (User::where('referral_code', $code)->exists()) {
            $randomChars = strtolower(Str::random(4));
            $code = "{$firstName}_{$randomChars}";
        }
        
        // Save the code to the user
        $user->referral_code = $code;
        $user->referral_code_created_at = now();
        $user->save();
        
        return $code;
    }
    
    /**
     * Process a referral when a new user signs up
     *
     * @param User $newUser
     * @param string|null $referralCode
     * @return array
     */
    public function processNewUserReferral(User $newUser, ?string $referralCode): array
    {
        if (!$referralCode) {
            return [
                'success' => false,
                'message' => 'No referral code provided'
            ];
        }
        
        try {
            DB::beginTransaction();
            
            // Find the referrer user - try with the exact code first
            $referrer = User::where('referral_code', $referralCode)->first();
            
            // If not found, try with a more flexible approach
            if (!$referrer) {
                // Check if the code is in the format 'name_random'
                if (preg_match('/^([a-zA-Z0-9]+)_([a-zA-Z0-9]+)$/', $referralCode, $matches)) {
                    $firstName = $matches[1];
                    $randomPart = $matches[2];
                    
                    // Try to find a user with a referral code in the new format (firstname_random)
                    $referrer = User::where('referral_code', $firstName . '_' . $randomPart)->first();
                    
                    // If still not found, try with the old format that might include domain
                    if (!$referrer) {
                        $referrer = User::where('referral_code', 'like', '%.' . $firstName . '_' . $randomPart)->first();
                    }
                    
                    // Last attempt - try with just the name_random part as a substring
                    if (!$referrer) {
                        $referrer = User::where('referral_code', 'like', '%' . $referralCode)->first();
                    }
                }
            }
            
            if (!$referrer) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'Invalid referral code'
                ];
            }
            
            // Check if the user is trying to refer themselves
            if ($referrer->id === $newUser->id) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'You cannot refer yourself'
                ];
            }
            
            // Set the referred_by field on the new user
            $newUser->referred_by = $referrer->id;
            $newUser->save();
            
            // Create a referral record
            $referral = new Referral([
                'referrer_id' => $referrer->id,
                'referred_id' => $newUser->id,
                'referral_code' => $referralCode,
                'referred_email' => $newUser->email
            ]);
            
            $referral->save();
            
            // Increment the referrer's referral count
            $referrer->referral_count = ($referrer->referral_count ?? 0) + 1;
            $referrer->save();
            
            DB::commit();
            
            return [
                'success' => true,
                'message' => 'Referral processed successfully',
                'referral' => $referral
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error processing referral: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Error processing referral: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Convert a referral when the referred user completes a qualifying action (e.g., makes a purchase)
     *
     * @param User $user
     * @param int|null $customTokensToAward Optional override for token amount
     * @return array
     */
    public function convertReferral(User $user, ?int $customTokensToAward = null): array
    {
        try {
            DB::beginTransaction();
            
            // Find the referral record
            $referral = Referral::where('referred_id', $user->id)->first();
            
            if (!$referral) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'No unconverted referral found for this user'
                ];
            }
            
            // Get the referrer
            $referrer = User::find($referral->referrer_id);
            
            if (!$referrer) {
                DB::rollBack();
                return [
                    'success' => false,
                    'message' => 'Referrer not found'
                ];
            }

            // Count total referrals for this referrer
            $totalReferrals = $referrer->referral_count;
            
            // Get the applicable tier based on total referrals
            $tier = ReferralTier::getTierByReferralCount($totalReferrals);
            
            if (!$tier) {
                // Fallback to default if no tier is found
                Log::warning("No referral tier found for count: {$totalReferrals}, using default values");
                $tokensForReferrer = 10;
                $tokensForReferee = 10;
                $tierName = 'Default';
            } else {
                // Use either custom tokens (if provided) or the tier's defined tokens
                $tokensForReferrer = $customTokensToAward ?? $tier->referrer_tokens;
                $tokensForReferee = $tier->referee_tokens;
                $tierName = $tier->name;
                
                // Update or create badge for the referrer
                $this->updateUserBadge($referrer, $tier);
                
                // Award subscription if tier provides it
                if ($tier->subscription_reward) {
                    $this->awardMilestoneSubscription(
                        $referrer, 
                        $tier->subscription_reward, 
                        $tier->subscription_months
                    );
                }
            }
            
            // Update the referral record
            $referral->is_converted = true;
            $referral->tokens_awarded = $referral->tokens_awarded + $tokensForReferrer;
            $referral->converted_at = now();
            $referral->save();
            
            // Add tokens to the referrer's free token balance
            $referrer->free_token = ($referrer->free_token ?? 0) + $tokensForReferrer;
            $referrer->save();
            
            // Add tokens to the referee's free token balance (the referred user)
            $user->free_token = ($user->free_token ?? 0) + $tokensForReferee;
            $user->save();
            
            DB::commit();
            
            return [
                'success' => true,
                'message' => 'Referral converted successfully',
                'referral' => $referral,
                'tokens_awarded' => [
                    'referrer' => $tokensForReferrer,
                    'referee' => $tokensForReferee
                ],
                'tier' => $tierName
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error converting referral: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Error converting referral: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Update or create a badge for a user based on their referral tier
     * 
     * @param User $user
     * @param ReferralTier $tier
     * @return void
     */
    private function updateUserBadge(User $user, ReferralTier $tier): void
    {
        try {
            UserBadge::updateOrCreate(
                ['user_id' => $user->id, 'badge_type' => 'referral'],
                [
                    'badge_level' => $tier->badge,
                    'description' => "Achieved {$tier->name} with {$user->referral_count} referrals",
                    'awarded_at' => now()
                ]
            );
        } catch (\Exception $e) {
            Log::error("Error updating user badge: {$e->getMessage()}");
        }
    }
    
    /**
     * Award milestone subscription rewards to a user
     * 
     * @param User $user
     * @param string $planType basic, pro, or enterprise
     * @param int $months
     * @return bool
     */
    private function awardMilestoneSubscription(User $user, string $planType, int $months): bool
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
            Log::info("Milestone reward: {$months} month(s) of {$planType} plan awarded to user {$user->id}");
            
            return true;
        } catch (\Exception $e) {
            Log::error("Error awarding milestone subscription: {$e->getMessage()}");
            return false;
        }
    }
    
    /**
     * Get all referrals for a user
     *
     * @param User $user
     * @return array
     */
    public function getUserReferrals(User $user): array
    {
        try {
            $referrals = Referral::where('referrer_id', $user->id)
                ->with('referred:id,name,email')
                ->orderBy('created_at', 'desc')
                ->get();
            
            $totalTokensEarned = $referrals->where('is_converted', true)->sum('tokens_awarded');
            $pendingReferrals = $referrals->where('is_converted', false)->count();
            $convertedReferrals = $referrals->where('is_converted', true)->count();
            
            // Get current tier and next tier information
            $currentTier = ReferralTier::getTierByReferralCount($user->referral_count);
            $nextTier = ReferralTier::getNextTier($user->referral_count);
            
            // Get user's referral badge if any
            $badge = $user->badges()->where('badge_type', 'referral')->first();
            
            return [
                'success' => true,
                'referrals' => $referrals,
                'stats' => [
                    'total_referrals' => $referrals->count(),
                    'pending_referrals' => $pendingReferrals,
                    'converted_referrals' => $convertedReferrals,
                    'total_tokens_earned' => $totalTokensEarned
                ],
                'tier' => [
                    'current' => $currentTier ? [
                        'name' => $currentTier->name,
                        'badge' => $currentTier->badge,
                        'min_referrals' => $currentTier->min_referrals,
                        'max_referrals' => $currentTier->max_referrals
                    ] : null,
                    'next' => $nextTier ? [
                        'name' => $nextTier->name,
                        'badge' => $nextTier->badge,
                        'min_referrals' => $nextTier->min_referrals,
                        'referrals_needed' => $nextTier->min_referrals - $user->referral_count
                    ] : null,
                ],
                'badge' => $badge ? [
                    'level' => $badge->badge_level,
                    'description' => $badge->description,
                    'awarded_at' => $badge->awarded_at
                ] : null
            ];
        } catch (\Exception $e) {
            Log::error('Error getting user referrals: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Error getting user referrals: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get referral tier status for a user
     *
     * @param User $user
     * @return array
     */
    public function getUserReferralStatus(User $user): array
    {
        try {
            // Get referral count
            $referralCount = $user->referral_count;
            
            // Get current tier
            $currentTier = ReferralTier::getTierByReferralCount($referralCount);
            
            // Get next tier
            $nextTier = ReferralTier::getNextTier($referralCount);
            
            // Get badge
            $badge = $user->badges()->where('badge_type', 'referral')->first();
            
            return [
                'success' => true,
                'data' => [
                    'user_id' => $user->id,
                    'referral_code' => $user->referral_code,
                    'referral_count' => $referralCount,
                    'current_tier' => $currentTier ? [
                        'id' => $currentTier->id,
                        'name' => $currentTier->name,
                        'badge' => $currentTier->badge,
                        'min_referrals' => $currentTier->min_referrals,
                        'max_referrals' => $currentTier->max_referrals,
                        'referrer_tokens' => $currentTier->referrer_tokens,
                        'referee_tokens' => $currentTier->referee_tokens,
                        'subscription_reward' => $currentTier->subscription_reward,
                    ] : null,
                    'next_tier' => $nextTier ? [
                        'id' => $nextTier->id,
                        'name' => $nextTier->name,
                        'badge' => $nextTier->badge,
                        'min_referrals' => $nextTier->min_referrals,
                        'referrals_needed' => $nextTier->min_referrals - $referralCount,
                        'referrer_tokens' => $nextTier->referrer_tokens,
                        'referee_tokens' => $nextTier->referee_tokens,
                        'subscription_reward' => $nextTier->subscription_reward,
                    ] : null,
                    'badge' => $badge ? [
                        'level' => $badge->badge_level,
                        'description' => $badge->description,
                        'awarded_at' => $badge->awarded_at->format('Y-m-d H:i:s'),
                    ] : null,
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Error getting user referral status: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Error getting user referral status: ' . $e->getMessage()
            ];
        }
    }
}

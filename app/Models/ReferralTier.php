<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferralTier extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'min_referrals',
        'max_referrals',
        'referrer_tokens',
        'referee_tokens',
        'badge',
        'subscription_reward',
        'subscription_months',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'min_referrals' => 'integer',
        'max_referrals' => 'integer',
        'referrer_tokens' => 'integer',
        'referee_tokens' => 'integer',
        'subscription_months' => 'integer',
    ];

    /**
     * Get a tier based on the number of referrals
     *
     * @param int $referralCount
     * @return self|null
     */
    public static function getTierByReferralCount(int $referralCount): ?self
    {
        return self::where('min_referrals', '<=', $referralCount)
            ->where(function ($query) use ($referralCount) {
                $query->where('max_referrals', '>=', $referralCount)
                    ->orWhereNull('max_referrals');
            })
            ->first();
    }

    /**
     * Get the next tier after the current referral count
     *
     * @param int $referralCount
     * @return self|null
     */
    public static function getNextTier(int $referralCount): ?self
    {
        return self::where('min_referrals', '>', $referralCount)
            ->orderBy('min_referrals', 'asc')
            ->first();
    }
}

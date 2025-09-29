<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesMilestoneTier extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'required_sales',
        'badge',
        'subscription_reward',
        'subscription_months',
        'cash_bonus',
        'has_physical_plaque',
        'perks',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'required_sales' => 'integer',
        'subscription_months' => 'integer',
        'cash_bonus' => 'decimal:2',
        'has_physical_plaque' => 'boolean',
    ];

    /**
     * Get tier based on number of sales
     *
     * @param int $salesCount
     * @return self|null
     */
    public static function getTierBySalesCount(int $salesCount): ?self
    {
        return self::where('required_sales', '<=', $salesCount)
            ->orderBy('required_sales', 'desc')
            ->first();
    }

    /**
     * Get the next tier after the current sales count
     *
     * @param int $salesCount
     * @return self|null
     */
    public static function getNextTier(int $salesCount): ?self
    {
        return self::where('required_sales', '>', $salesCount)
            ->orderBy('required_sales', 'asc')
            ->first();
    }
}

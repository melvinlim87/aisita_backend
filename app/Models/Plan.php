<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'price',
        'regular_price',
        'discount_percentage',
        'has_discount',
        'currency',
        'interval', // 'monthly', 'yearly'
        'tokens_per_cycle',
        'features',
        'stripe_price_id',
        'stripe_price_id_live',
        'is_active',
        'premium_models_access'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'float',
        'regular_price' => 'float',
        'discount_percentage' => 'float',
        'has_discount' => 'boolean',
        'tokens_per_cycle' => 'integer',
        'features' => 'array',
        'is_active' => 'boolean',
        'premium_models_access' => 'boolean'
    ];
    
    /**
     * Get the appended attributes for the model.
     *
     * @return array
     */
    protected $appends = ['savings', 'savings_percentage'];

    /**
     * Get the subscriptions for this plan.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
    
    /**
     * Calculate the savings amount compared to regular price.
     *
     * @return float
     */
    public function getSavingsAttribute(): float
    {
        if (!$this->has_discount || $this->regular_price === null) {
            return 0;
        }
        
        return $this->regular_price - $this->price;
    }
    
    /**
     * Calculate the savings percentage.
     *
     * @return float
     */
    public function getSavingsPercentageAttribute(): float
    {
        if (!$this->has_discount || $this->regular_price === null || $this->regular_price == 0) {
            return 0;
        }
        
        return round(($this->savings / $this->regular_price) * 100, 1);
    }
    
    /**
     * Apply standard discounts based on interval.
     *
     * @param float $basePrice The base price to apply discount to
     * @return void
     */
    public function applyStandardDiscount(float $basePrice): void
    {
        if ($this->interval === 'monthly') {
            // 12% discount for monthly plans
            $this->regular_price = $basePrice;
            $this->discount_percentage = 12;
            $this->price = round($basePrice * 0.88, 2);
            $this->has_discount = true;
        } elseif ($this->interval === 'yearly') {
            // 34% discount for yearly plans
            $this->regular_price = $basePrice * 12; // Annual full price
            $this->discount_percentage = 34;
            $this->price = round($basePrice * 12 * 0.66, 2);
            $this->has_discount = true;
        } else {
            // No discount for other intervals
            $this->price = $basePrice;
            $this->regular_price = null;
            $this->discount_percentage = null;
            $this->has_discount = false;
        }
    }

    /**
     * Accessor: resolve the Stripe price ID based on environment/mode.
     * - In production or when STRIPE_MODE=live, prefer stripe_price_id_live if set
     * - Otherwise fall back to the original stripe_price_id (test/sandbox)
     */
    public function getStripePriceIdAttribute($value): ?string
    {
        $mode = env('STRIPE_MODE', app()->environment('production') ? 'live' : 'test');
        if ($mode === 'live') {
            return $this->attributes['stripe_price_id_live'] ?? $this->attributes['stripe_price_id'] ?? $value;
        }
        return $value;
    }
}

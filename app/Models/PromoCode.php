<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class PromoCode extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'code',
        'description',
        'type',
        'value',
        'max_uses',
        'used_count',
        'max_uses_per_user',
        'plan_id',
        'is_active',
        'starts_at',
        'expires_at'
    ];
    
    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'value' => 'decimal:2',
    ];
    
    /**
     * Check if the promo code is valid
     * 
     * @return bool
     */
    public function isValid(): bool
    {
        // Check if code is active
        if (!$this->is_active) {
            return false;
        }
        
        // Check if code has started
        if ($this->starts_at && Carbon::now()->lt($this->starts_at)) {
            return false;
        }
        
        // Check if code has expired
        if ($this->expires_at && Carbon::now()->gt($this->expires_at)) {
            return false;
        }
        
        // Check if maximum uses reached
        if ($this->max_uses > 0 && $this->used_count >= $this->max_uses) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if user can use this promo code
     * 
     * @param int $userId
     * @return bool
     */
    public function canBeUsedByUser(int $userId): bool
    {
        if (!$this->isValid()) {
            return false;
        }
        
        // Check user usage limit
        $userUsage = $this->users()->where('user_id', $userId)->count();
        
        return $userUsage < $this->max_uses_per_user;
    }
    
    /**
     * Get users who have used this promo code
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'promo_code_user')
            ->withPivot('subscription_id', 'used_at')
            ->withTimestamps();
    }
    
    /**
     * Get the plan associated with this promo code
     */
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }
    
    /**
     * Calculate the discount amount for a given price
     * 
     * @param float $price
     * @return float
     */
    public function calculateDiscount(float $price): float
    {
        switch ($this->type) {
            case 'percentage':
                return round($price * ($this->value / 100), 2);
            
            case 'fixed':
                return min($this->value, $price);
                
            case 'free_month':
                return $price;
                
            default:
                return 0;
        }
    }
}

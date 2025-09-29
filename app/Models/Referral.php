<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Referral extends Model
{
    use HasFactory;
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'referrer_id',
        'referred_id',
        'referred_email',
        'referral_code',
        'is_converted',
        'tokens_awarded',
        'converted_at',
    ];
    
    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_converted' => 'boolean',
        'tokens_awarded' => 'integer',
        'converted_at' => 'datetime',
    ];
    
    /**
     * Get the user who referred another user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }
    
    /**
     * Get the user who was referred.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_id');
    }
}

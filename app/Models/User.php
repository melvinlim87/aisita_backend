<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'firebase_uid',
        'telegram_id',
        'telegram_username',
        'password',
        'role_id',
        'subscription_token',
        'registration_token',
        'free_token',
        'addons_token',
        'referral_code',
        'referral_count',
        'referred_by',
        'referral_code_created_at',
        'phone_number',
        'whatsapp_verified',
        'street_address',
        'city',
        'state',
        'zip_code',
        'country',
        'date_of_birth',
        'gender',
        'profile_picture_url',
        'free_plan_used',
        'last_login_at',
        'disabled',
        'verification_code'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }
    
    /**
     * Get the role that owns the user.
     */
    public function role()
    {
        return $this->belongsTo(Role::class);
    }
    
    /**
     * Check if the user has a specific role.
     *
     * @param string $roleName
     * @return bool
     */
    public function hasRole(string $roleName): bool
    {
        return $this->role && $this->role->name === $roleName;
    }
    
    /**
     * Check if the user is an admin.
     *
     * @return bool
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin') || $this->hasRole('super_admin');
    }
    
    /**
     * Check if the user is a super admin.
     *
     * @return bool
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }
    
    /**
     * Get the purchases for the user.
     */
    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }
    
    /**
     * Get the user who referred this user.
     */
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }
    
    /**
     * Get the users that this user has referred.
     */
    public function referrals()
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }
    
    /**
     * Get the referral where this user was referred.
     */
    public function referredBy()
    {
        return $this->hasMany(Referral::class, 'referred_id');
    }
    
    /**
     * Get the support tickets created by the user.
     */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }
    
    /**
     * Get the support tickets assigned to the user (admin).
     */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'assigned_to');
    }
    
    /**
     * Get all ticket replies by this user.
     */
    public function ticketReplies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }
    
    /**
     * Get the user's active subscription.
     */
    public function subscription()
    {
        return $this->hasOne(Subscription::class)->whereIn('status', ['active', 'trialing']);
    }
    
    /**
     * Get all user's subscriptions including inactive ones.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
    
    /**
     * Check if the user has an active subscription.
     *
     * @return bool
     */
    public function hasActiveSubscription(): bool
    {
        return $this->subscription && $this->subscription->isActive();
    }
    
    /**
     * Check if the user has access to premium models.
     *
     * @return bool
     */
    public function hasPremiumAccess(): bool
    {
        return $this->hasActiveSubscription() && $this->subscription->plan->premium_models_access;
    }
    
    /**
     * Get the user's active subscription.
     * 
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function getActiveSubscriptionAttribute()
    {
        return $this->subscription;
    }
    
    /**
     * Get all badges earned by the user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function badges(): HasMany
    {
        return $this->hasMany(UserBadge::class);
    }
}

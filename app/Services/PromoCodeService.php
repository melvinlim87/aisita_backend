<?php

namespace App\Services;

use App\Models\PromoCode;
use App\Models\User;
use App\Models\Subscription;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PromoCodeService
{
    /**
     * Validate a promo code
     *
     * @param string $code
     * @param int $userId
     * @param int|null $planId
     * @return array
     */
    public function validatePromoCode(string $code, int $userId, ?int $planId = null): array
    {
        try {
            $promoCode = PromoCode::where('code', $code)->first();
            
            if (!$promoCode) {
                return [
                    'valid' => false,
                    'message' => 'Invalid promotion code'
                ];
            }
            
            if (!$promoCode->isValid()) {
                return [
                    'valid' => false,
                    'message' => 'This promotion code is no longer valid'
                ];
            }
            
            if (!$promoCode->canBeUsedByUser($userId)) {
                return [
                    'valid' => false,
                    'message' => 'You have already used this promotion code'
                ];
            }
            
            // Check if code is restricted to a specific plan
            if ($promoCode->plan_id && $planId && $promoCode->plan_id != $planId) {
                return [
                    'valid' => false,
                    'message' => 'This promotion code is not valid for the selected plan'
                ];
            }
            
            return [
                'valid' => true,
                'promo_code' => $promoCode,
                'discount_type' => $promoCode->type,
                'discount_value' => $promoCode->value,
                'message' => 'Promotion code applied successfully'
            ];
        } catch (\Exception $e) {
            Log::error('Error validating promo code: ' . $e->getMessage());
            return [
                'valid' => false,
                'message' => 'An error occurred while validating the promotion code'
            ];
        }
    }
    
    /**
     * Apply a promo code to a subscription
     *
     * @param PromoCode $promoCode
     * @param User $user
     * @param Subscription $subscription
     * @return array
     */
    public function applyPromoCode(PromoCode $promoCode, User $user, Subscription $subscription): array
    {
        try {
            DB::beginTransaction();
            
            // Record usage
            $user->promoCodes()->attach($promoCode->id, [
                'subscription_id' => $subscription->id,
                'used_at' => now()
            ]);
            
            // Increment usage count
            $promoCode->used_count++;
            $promoCode->save();
            
            // Handle different promo code types
            $plan = $subscription->plan;
            $metadata = json_decode($subscription->metadata ?? '{}', true);
            
            switch ($promoCode->type) {
                case 'free_month':
                    // Add a free month to the subscription
                    if ($subscription->next_billing_date) {
                        $subscription->next_billing_date = $subscription->next_billing_date->addMonth();
                    } else {
                        $subscription->next_billing_date = now()->addMonth();
                    }
                    
                    // Record promo code application in metadata
                    $metadata['promo_codes'] = $metadata['promo_codes'] ?? [];
                    $metadata['promo_codes'][] = [
                        'code' => $promoCode->code,
                        'type' => $promoCode->type,
                        'applied_at' => now()->format('Y-m-d H:i:s'),
                        'free_month_added' => true
                    ];
                    
                    $subscription->metadata = json_encode($metadata);
                    $subscription->save();
                    
                    DB::commit();
                    return [
                        'success' => true,
                        'message' => 'One free month has been added to your subscription',
                        'next_billing_date' => $subscription->next_billing_date->format('Y-m-d')
                    ];
                    
                case 'percentage':
                case 'fixed':
                    // Calculate discount
                    $discount = $promoCode->calculateDiscount($plan->price);
                    $discountedPrice = max(0, $plan->price - $discount);
                    
                    // Create a one-time discount in Stripe
                    // Note: Actual Stripe implementation would go here
                    
                    // Record promo code application in metadata
                    $metadata['promo_codes'] = $metadata['promo_codes'] ?? [];
                    $metadata['promo_codes'][] = [
                        'code' => $promoCode->code,
                        'type' => $promoCode->type,
                        'value' => $promoCode->value,
                        'discount_amount' => $discount,
                        'original_price' => $plan->price,
                        'discounted_price' => $discountedPrice,
                        'applied_at' => now()->format('Y-m-d H:i:s')
                    ];
                    
                    $subscription->metadata = json_encode($metadata);
                    $subscription->save();
                    
                    DB::commit();
                    return [
                        'success' => true,
                        'message' => "Discount of \${$discount} applied to your subscription",
                        'discount_amount' => $discount,
                        'original_price' => $plan->price,
                        'discounted_price' => $discountedPrice
                    ];
                    
                default:
                    throw new \Exception('Unknown promotion code type');
            }
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error applying promo code: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to apply promotion code: ' . $e->getMessage()
            ];
        }
    }
}

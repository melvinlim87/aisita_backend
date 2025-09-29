<?php

namespace App\Services;

use App\Models\User;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe;

class SubscriptionService
{
    /**
     * The token service instance.
     *
     * @var \App\Services\TokenService
     */
    protected $tokenService;
    
    /**
     * Create a new service instance.
     *
     * @param  \App\Services\TokenService  $tokenService
     * @return void
     */
    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }
    
    /**
     * Create a new subscription for a user.
     *
     * @param User $user
     * @param Plan $plan
     * @param array $subscriptionData
     * @return array
     */
    public function createSubscription(User $user, Plan $plan, array $subscriptionData): array
    {
        try {
            DB::beginTransaction();
            
            // Create the subscription
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'stripe_subscription_id' => $subscriptionData['stripe_subscription_id'] ?? null,
                'status' => $subscriptionData['status'] ?? 'active',
                'trial_ends_at' => isset($subscriptionData['trial_ends_at']) ? now()->addDays($subscriptionData['trial_ends_at']) : null,
                'next_billing_date' => $subscriptionData['next_billing_date'] ?? now()->addMonth(),
                'canceled_at' => null,
                'ends_at' => null,
            ]);
            
            // Award initial tokens from the plan
            if ($plan->tokens_per_cycle > 0) {
                $purchaseData = [
                    'sessionId' => 'subscription-' . $subscription->id,
                    'priceId' => $plan->stripe_price_id,
                    'amount' => $plan->price,
                    'status' => 'completed',
                    'customerEmail' => $user->email,
                    'currency' => $plan->currency,
                    'type' => 'subscription'
                ];
                
                $this->tokenService->updateUserTokens(
                    $user->id, 
                    $plan->tokens_per_cycle, 
                    $purchaseData
                );
            }

            // check if plan is free plan, if is free plan update user free_plan_used
            $plan = Plan::find($plan->id);
            if ($plan->name == 'Free') {
                User::where('id', $user->id)->update(['free_plan_used' => true]);
            }
            
            
            DB::commit();
            
            return [
                'success' => true,
                'subscription' => $subscription,
                'message' => 'Subscription created successfully'
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error creating subscription: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to create subscription: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Process a subscription renewal.
     *
     * @param Subscription $subscription
     * @return array
     */
    public function processRenewal(Subscription $subscription): array
    {
        try {
            DB::beginTransaction();
            
            $user = $subscription->user;
            $plan = $subscription->plan;
            
            // Check if there's a pending downgrade that needs to be processed
            $metadata = $subscription->metadata ?? [];
            $pendingDowngrade = $metadata['pending_downgrade'] ?? false;
            
            if ($pendingDowngrade && isset($metadata['downgrade_plan_id'])) {
                // Apply the pending downgrade
                $newPlanId = $metadata['downgrade_plan_id'];
                $newPlan = Plan::find($newPlanId);
                
                if ($newPlan) {
                    Log::info("Processing pending downgrade for subscription {$subscription->id} from plan {$plan->id} to plan {$newPlanId}");
                    
                    // Update plan in Stripe
                    try {
                        // Set Stripe API key
                        \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
                        
                        // Retrieve the subscription
                        $stripeSubscription = \Stripe\Subscription::retrieve([
                            'id' => $subscription->stripe_subscription_id,
                            'expand' => ['items.data']
                        ]);
                        
                        if (!$stripeSubscription) {
                            throw new \Exception('Could not retrieve subscription from Stripe');
                        }
                        
                        // Get subscription item ID
                        if (count($stripeSubscription->items->data) === 0) {
                            throw new \Exception('No subscription items found in Stripe');
                        }
                        
                        $subscriptionItemId = $stripeSubscription->items->data[0]->id;
                        
                        // Update price in Stripe
                        \Stripe\Subscription::update(
                            $subscription->stripe_subscription_id,
                            [
                                'items' => [
                                    [
                                        'id' => $subscriptionItemId,
                                        'price' => $newPlan->stripe_price_id,
                                    ],
                                ],
                                'metadata' => [
                                    'plan_id' => (string)$newPlan->id,
                                    'previous_plan_id' => (string)$plan->id,
                                    'downgrade_applied_at' => now()->format('Y-m-d H:i:s')
                                ]
                            ]
                        );
                        
                    } catch (\Stripe\Exception\ApiErrorException $e) {
                        // Log error but continue with local database update
                        Log::error("Stripe API error during downgrade: " . $e->getMessage());
                    }
                    
                    // Update local subscription
                    $subscription->plan_id = $newPlan->id;
                    
                    // Remove downgrade metadata
                    unset($metadata['pending_downgrade']);
                    unset($metadata['downgrade_plan_id']);
                    unset($metadata['downgrade_effective_date']);
                    $subscription->metadata = $metadata;
                    
                    // Update plan to the downgraded plan
                    $plan = $newPlan;
                }
            }
            
            // Update next billing date
            if (strpos(strtolower($plan->interval), 'year') !== false) {
                $subscription->next_billing_date = now()->addYear();
            } else {
                $subscription->next_billing_date = now()->addMonth();
            }
            
            $subscription->save();
            
            // Reset subscription tokens to zero before awarding new tokens
            $user->subscription_token = 0;
            $user->save();
            
            // Award tokens for the renewal
            if ($plan->tokens_per_cycle > 0) {
                $purchaseData = [
                    'sessionId' => 'subscription-renewal-' . $subscription->id . '-' . now()->timestamp,
                    'priceId' => $plan->stripe_price_id,
                    'amount' => $plan->price,
                    'status' => 'completed',
                    'customerEmail' => $user->email,
                    'currency' => $plan->currency ?? 'usd',
                    'type' => 'subscription_renewal'
                ];
                
                $this->tokenService->updateUserTokens(
                    $user->id, 
                    $plan->tokens_per_cycle, 
                    $purchaseData,
                    'subscription_token'
                );
            }
            
            DB::commit();
            
            return [
                'success' => true,
                'message' => 'Subscription renewed successfully'
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error renewing subscription: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to renew subscription: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Cancel a subscription.
     *
     * @param Subscription $subscription
     * @param bool $immediate Whether to end the subscription immediately or at the end of the billing period
     * @return array
     */
    public function cancelSubscription(Subscription $subscription, bool $immediate = false): array
    {
        try {
            \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
    
            DB::beginTransaction();
            
            $subscription->canceled_at = now();
            
            if ($immediate) {
                $subscription->status = 'canceled';
                $subscription->ends_at = now();
                
                // Flush subscription tokens immediately but keep top-up tokens
                $user = $subscription->user;
                $userToken = UserToken::where('user_id', $user->id)->first();
                
                if ($userToken) {
                    Log::info("Flushing subscription tokens for user {$user->id} due to immediate cancellation. Before: {$userToken->subscription_token}");
                    $userToken->subscription_token = 0;
                    $userToken->save();
                    Log::info("Top-up tokens preserved: {$userToken->addons_token}");
                }
                \Stripe\Subscription::update($subscription->stripe_subscription_id, [
                    'cancel_at_period_end' => false,
                ]);
                \Stripe\Subscription::cancel($subscription->stripe_subscription_id);
            } else {
                // Will remain active until the end of the current period
                $subscription->ends_at = $subscription->next_billing_date;
                // Tokens will be flushed at the end of billing period
                \Stripe\Subscription::update($subscription->stripe_subscription_id, [
                    'cancel_at_period_end' => true,
                ]);
            }
            
            $subscription->save();
            DB::commit();
            return [
                'success' => true,
                'message' => $immediate ? 
                    'Subscription canceled immediately' : 
                    'Subscription will be canceled at the end of the current billing period'
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error canceling subscription: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to cancel subscription: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Change a subscription's plan.
     *
     * @param Subscription $subscription
     * @param Plan $newPlan
     * @return array
     */
    /**
     * Calculate the remaining value of a subscription based on remaining days
     *
     * @param Subscription $subscription The current subscription
     * @return array ['remainingDays' => int, 'totalDays' => int, 'remainingValue' => float]
     */
    public function calculateRemainingSubscriptionValue(Subscription $subscription): array
    {
        $currentDate = now();
        $nextBillingDate = $subscription->next_billing_date;
        $startDate = $nextBillingDate->copy();
        
        // Determine billing cycle length based on plan interval
        if (strpos(strtolower($subscription->plan->interval), 'year') !== false) {
            $totalDays = 365; // Use 365 days for yearly plans
            $startDate->subYear();
        } else {
            $totalDays = 30; // Use 30 days for monthly plans
            $startDate->subMonth();
        }
        
        // Calculate days remaining in the current billing cycle
        $remainingDays = $currentDate->diffInDays($nextBillingDate);
        if ($remainingDays < 0) $remainingDays = 0;
        
        // Calculate proration factor and remaining value
        $prorationFactor = $remainingDays / $totalDays;
        $remainingValue = $prorationFactor * $subscription->plan->price;
        
        return [
            'remainingDays' => $remainingDays,
            'totalDays' => $totalDays,
            'remainingValue' => $remainingValue
        ];
    }

    public function changePlan(Subscription $subscription, Plan $newPlan): array
    {
        try {
            // Verify the subscription has a Stripe subscription ID
            if (empty($subscription->stripe_subscription_id)) {
                return [
                    'success' => false,
                    'message' => 'No Stripe subscription ID found for this subscription'
                ];
            }

            // Verify the new plan has a Stripe price ID
            if (empty($newPlan->stripe_price_id)) {
                return [
                    'success' => false,
                    'message' => 'New plan does not have a valid Stripe price ID'
                ];
            }

            DB::beginTransaction();
            
            $oldPlan = $subscription->plan;
            $user = $subscription->user;
            
            // Check if this is an upgrade (higher price) or downgrade (lower price)
            $isUpgrade = $newPlan->price > $oldPlan->price;
            $isDowngrade = $newPlan->price < $oldPlan->price;

            if ($isUpgrade) {
                \Log::info("User ready upgrade plan");
                
                // Mid-Cycle Upgrade Logic
                // Get current subscription metadata to check for previous upgrades in this cycle
                $metadata = json_decode($subscription->metadata ?? '{}', true);
                $originalPlanId = $metadata['original_plan_id'] ?? null;
                
                // If this is the first upgrade in the cycle, store the original plan
                if (!$originalPlanId) {
                    $originalPlanId = $oldPlan->id;
                    $metadata['original_plan_id'] = $originalPlanId;
                    $originalPlan = $oldPlan;
                } else {
                    // If there was a previous upgrade, use the original plan for proration
                    $originalPlan = Plan::find($originalPlanId) ?? $oldPlan;
                }
                
                // Calculate remaining value using the original plan, not the intermediate ones
                $remainingValueData = $this->calculateRemainingSubscriptionValue($subscription);
                $remainingDays = $remainingValueData['remainingDays'];
                $totalDays = $remainingValueData['totalDays'];
                
                // Calculate proration factor
                $prorationFactor = $remainingDays / $totalDays;
                
                // Use original plan price to calculate remaining value
                $remainingValue = $prorationFactor * $originalPlan->price;
                
                // Calculate new charge based on difference from original plan
                $newCharge = $newPlan->price - $remainingValue;
                if ($newCharge < 0) $newCharge = 0;
                
                // Store upgrade history in metadata as a JSON string
                $upgradeHistoryData = [];
                
                // If we have existing upgrade history, decode it from JSON
                if (!empty($metadata['upgrade_history'])) {
                    try {
                        $upgradeHistoryData = json_decode($metadata['upgrade_history'], true) ?? [];
                    } catch (\Exception $e) {
                        // If we can't decode it, just start with an empty array
                        $upgradeHistoryData = [];
                    }
                }
                
                // Add the new upgrade event
                $upgradeHistoryData[] = [
                    'date' => now()->format('Y-m-d H:i:s'),
                    'from_plan_id' => $oldPlan->id,
                    'to_plan_id' => $newPlan->id
                ];
                
                // Convert the array back to a JSON string for Stripe metadata
                $metadata['upgrade_history'] = json_encode($upgradeHistoryData);
                
                // Update subscription in Stripe
                try {
                    // Set Stripe API key
                    \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
    
                    // Retrieve the subscription from Stripe
                    $stripeSubscription = \Stripe\Subscription::retrieve([
                        'id' => $subscription->stripe_subscription_id,
                        'expand' => ['items.data']
                    ]);
    
                    if (!$stripeSubscription) {
                        throw new \Exception('Could not retrieve subscription from Stripe');
                    }
    
                    // Get the subscription item ID
                    if (count($stripeSubscription->items->data) === 0) {
                        throw new \Exception('No subscription items found in Stripe');
                    }
                    
                    $subscriptionItemId = $stripeSubscription->items->data[0]->id;
    
                    // Update the subscription in Stripe
                    $updatedStripeSubscription = \Stripe\Subscription::update(
                        $subscription->stripe_subscription_id,
                        [
                            'items' => [
                                [
                                    'id' => $subscriptionItemId,
                                    'price' => $newPlan->stripe_price_id,
                                ],
                            ],
                            // Create a prorated invoice for the price difference
                            'proration_behavior' => 'always_invoice',
                            'proration_date' => time(),
                            'metadata' => [
                                'plan_id' => (string)$newPlan->id,
                                'previous_plan_id' => (string)$oldPlan->id,
                                'original_plan_id' => (string)$originalPlanId,
                                'remaining_value' => (string)$remainingValue,
                                'new_charge' => (string)$newCharge
                            ]
                        ]
                    );
                    
                    // Create an invoice for the upgrade
                    $invoice = \Stripe\Invoice::create([
                        'customer' => $stripeSubscription->customer,
                        'subscription' => $subscription->stripe_subscription_id,
                        'auto_advance' => true,
                        'description' => "Upgrade from {$oldPlan->name} to {$newPlan->name}",
                    ]);
                    
                    if ($invoice->amount_due > 0) {
                        $invoice->pay();
                    }
    
                    Log::info("Subscription upgraded successfully: {$subscription->stripe_subscription_id}");
                    Log::info("Remaining value: {$remainingValue}, New charge: {$newCharge}");
                    
                    // Update next billing date if it changed
                    if (isset($updatedStripeSubscription->current_period_end)) {
                        $subscription->next_billing_date = date('Y-m-d H:i:s', $updatedStripeSubscription->current_period_end);
                    }
                    
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    throw new \Exception("Stripe API error during upgrade: " . $e->getMessage());
                }
                
                // Update local database
                $subscription->plan_id = $newPlan->id;
                $subscription->save();
                
                // Handle tokens: Flush old subscription tokens and allocate new ones
                // 1. Calculate remaining subscription tokens (for logging purposes)
                $user = $subscription->user;
                $oldRemainingTokens = $user->subscription_token;
                
                // 2. Reset subscription tokens to zero
                $user->subscription_token = 0;
                $user->save();
                
                // 3. Check if this is a Free plan and user has had it before
                $skipTokenAllocation = false;
                if ($newPlan->price == 0 && $newPlan->name === 'Free') {
                    // Check if user has previously had a Free plan subscription
                    $previousFreePlanSub = Subscription::where('user_id', $user->id)
                        ->where('id', '!=', $subscription->id) // Exclude current subscription
                        ->whereHas('plan', function($query) {
                            $query->where('name', 'Free')->where('price', 0);
                        })
                        ->exists();
                    
                    // If user has previously had a Free plan, skip token allocation
                    if ($previousFreePlanSub) {
                        Log::info("User {$user->id} has previously had a Free plan subscription. Skipping Free plan token allocation.");
                        $skipTokenAllocation = true;
                    }
                }
                
                // Only allocate tokens if we're not skipping allocation
                if (!$skipTokenAllocation) {
                    // 3. Allocate new plan's tokens immediately
                    $purchaseData = [
                        'sessionId' => 'plan-upgrade-' . $subscription->id . '-' . now()->timestamp,
                        'priceId' => $newPlan->stripe_price_id,
                        'amount' => $newPlan->price,
                        'status' => 'completed',
                        'customerEmail' => $user->email,
                        'currency' => $newPlan->currency ?? 'usd',
                        'type' => 'plan_upgrade'
                    ];
                    
                    $this->tokenService->updateUserTokens(
                        $user->id, 
                        $newPlan->tokens_per_cycle,
                        $purchaseData,
                        'subscription_token'
                    );
                    
                    Log::info("Tokens reset from {$oldRemainingTokens} to 0 and new allocation of {$newPlan->tokens_per_cycle} tokens");
                } else {
                    Log::info("Skipped token allocation for Free plan as user {$user->id} has already received Free plan tokens before.");
                }
                
                $message = "Subscription upgraded successfully. You've been charged the prorated amount and received your new plan's tokens immediately."; 
            } 
            elseif ($isDowngrade) {
                \Log::info("User ready downgrade plan");
                // Mid-Cycle Downgrade Logic - Takes effect next billing cycle
                // Flag this as a downgrade scheduled for next billing cycle
                $metadata = json_decode($subscription->metadata ?? '{}', true);
                $originalPlanId = $metadata['original_plan_id'] ?? null;
                
                // If this is the first upgrade in the cycle, store the original plan
                if (!$originalPlanId) {
                    $originalPlanId = $oldPlan->id;
                    $metadata['original_plan_id'] = $originalPlanId;
                    $originalPlan = $oldPlan;
                } else {
                    // If there was a previous upgrade, use the original plan for proration
                    $originalPlan = Plan::find($originalPlanId) ?? $oldPlan;
                }
                
                // Calculate remaining value using the original plan, not the intermediate ones
                $remainingValueData = $this->calculateRemainingSubscriptionValue($subscription);
                $remainingDays = $remainingValueData['remainingDays'];
                $totalDays = $remainingValueData['totalDays'];
                
                // Calculate proration factor
                $prorationFactor = $remainingDays / $totalDays;
                
                // Use original plan price to calculate remaining value
                $remainingValue = $prorationFactor * $originalPlan->price;
                
                // Calculate new charge based on difference from original plan
                $newCharge = $newPlan->price - $remainingValue;
                if ($newCharge < 0) $newCharge = 0;
                
                // Store upgrade history in metadata
                $metadata['upgrade_history'] = ($metadata['upgrade_history'] ?? []);
                $metadata['upgrade_history'][] = [
                    'date' => now()->format('Y-m-d H:i:s'),
                    'from_plan_id' => $oldPlan->id,
                    'to_plan_id' => $newPlan->id
                ];
                
                // Update subscription in Stripe
                try {
                    // Set Stripe API key
                    \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
    
                    // Retrieve the subscription from Stripe
                    $stripeSubscription = \Stripe\Subscription::retrieve([
                        'id' => $subscription->stripe_subscription_id,
                        'expand' => ['items.data']
                    ]);
    
                    if (!$stripeSubscription) {
                        throw new \Exception('Could not retrieve subscription from Stripe');
                    }
    
                    // Get the subscription item ID
                    if (count($stripeSubscription->items->data) === 0) {
                        throw new \Exception('No subscription items found in Stripe');
                    }
                    
                    $subscriptionItemId = $stripeSubscription->items->data[0]->id;
    
                    // Update the subscription in Stripe
                    $updatedStripeSubscription = \Stripe\Subscription::update(
                        $subscription->stripe_subscription_id,
                        [
                            'items' => [
                                [
                                    'id' => $subscriptionItemId,
                                    'price' => $newPlan->stripe_price_id,
                                ],
                            ],
                            // Create a prorated invoice for the price difference
                            // create_prorations
                            'proration_behavior' => 'always_invoice',
                            'proration_date' => time(),
                            'metadata' => [
                                'plan_id' => (string)$newPlan->id,
                                'previous_plan_id' => (string)$oldPlan->id,
                                'original_plan_id' => (string)$originalPlanId,
                                'remaining_value' => (string)$remainingValue,
                                'new_charge' => (string)$newCharge
                            ]
                        ]
                    );
                    
                    // Create an invoice for the upgrade
                    $invoice = \Stripe\Invoice::create([
                        'customer' => $stripeSubscription->customer,
                        'subscription' => $subscription->stripe_subscription_id,
                        'auto_advance' => true,
                        'description' => "Downgrade from {$oldPlan->name} to {$newPlan->name}",
                    ]);
                    
                    if ($invoice->amount_due > 0) {
                        $invoice->pay();
                    }
    
                    Log::info("Subscription upgraded successfully: {$subscription->stripe_subscription_id}");
                    Log::info("Remaining value: {$remainingValue}, New charge: {$newCharge}");
                    
                    // Update next billing date if it changed
                    if (isset($updatedStripeSubscription->current_period_end)) {
                        $subscription->next_billing_date = date('Y-m-d H:i:s', $updatedStripeSubscription->current_period_end);
                    }
                    
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    throw new \Exception("Stripe API error during upgrade: " . $e->getMessage());
                }
                
                // Update local database
                $subscription->plan_id = $newPlan->id;
                $subscription->save();
                
                // Handle tokens: Flush old subscription tokens and allocate new ones
                // 1. Calculate remaining subscription tokens (for logging purposes)
                $user = $subscription->user;
                $oldRemainingTokens = $user->subscription_token;
                
                // 2. Reset subscription tokens to zero
                $user->subscription_token = 0;
                $user->save();
                
                // Check if this is a Free plan and user has had it before
                $skipTokenAllocation = false;
                if ($newPlan->price == 0 && $newPlan->name === 'Free') {
                    // Check if user has previously had a Free plan subscription
                    $previousFreePlanSub = Subscription::where('user_id', $user->id)
                        ->where('id', '!=', $subscription->id) // Exclude current subscription
                        ->whereHas('plan', function($query) {
                            $query->where('name', 'Free')->where('price', 0);
                        })
                        ->exists();
                    
                    // If user has previously had a Free plan, skip token allocation
                    if ($previousFreePlanSub) {
                        Log::info("User {$user->id} has previously had a Free plan subscription. Skipping Free plan token allocation.");
                        $skipTokenAllocation = true;
                    }
                }
                
                // Only allocate tokens if we're not skipping allocation
                if (!$skipTokenAllocation) {
                    // Allocate new plan's tokens
                    $purchaseData = [
                        'sessionId' => 'plan-change-' . $subscription->id . '-' . now()->timestamp,
                        'priceId' => $newPlan->stripe_price_id,
                        'amount' => $newPlan->price,
                        'status' => 'completed',
                        'customerEmail' => $user->email,
                        'currency' => $newPlan->currency ?? 'usd',
                        'type' => 'plan_change'
                    ];
                    
                    $this->tokenService->updateUserTokens(
                        $user->id, 
                        $newPlan->tokens_per_cycle,
                        $purchaseData,
                        'subscription_token'
                    );
                } else {
                    Log::info("Skipped token allocation for Free plan as user {$user->id} has already received Free plan tokens before.");
                }
                // We don't change the plan_id yet - it will change on next billing cycle
                $message = "Your downgrade to the {$newPlan->name} plan has been scheduled and will take effect on {$subscription->next_billing_date->format('Y-m-d')}. Until then, you will continue with your current plan."; 
            }
            else {
                \Log::info("Same price but different plan");
                
                // Same price, but different plan (e.g., different features)
                // Update subscription in Stripe
                try {
                    // Set Stripe API key
                    \Stripe\Stripe::setApiKey(env('STRIPE_SECRET_KEY'));
    
                    // Retrieve the subscription from Stripe
                    $stripeSubscription = \Stripe\Subscription::retrieve([
                        'id' => $subscription->stripe_subscription_id,
                        'expand' => ['items.data']
                    ]);
    
                    if (!$stripeSubscription) {
                        throw new \Exception('Could not retrieve subscription from Stripe');
                    }
    
                    // Get the subscription item ID
                    if (count($stripeSubscription->items->data) === 0) {
                        throw new \Exception('No subscription items found in Stripe');
                    }
                    
                    $subscriptionItemId = $stripeSubscription->items->data[0]->id;
    
                    // Update the subscription in Stripe
                    $updatedStripeSubscription = \Stripe\Subscription::update(
                        $subscription->stripe_subscription_id,
                        [
                            'items' => [
                                [
                                    'id' => $subscriptionItemId,
                                    'price' => $newPlan->stripe_price_id,
                                ],
                            ],
                            'metadata' => [
                                'plan_id' => (string)$newPlan->id,
                                'previous_plan_id' => (string)$oldPlan->id
                            ]
                        ]
                    );
                    
                    // Update next billing date if it changed
                    if (isset($updatedStripeSubscription->current_period_end)) {
                        $subscription->next_billing_date = date('Y-m-d H:i:s', $updatedStripeSubscription->current_period_end);
                    }
                    
                } catch (\Stripe\Exception\ApiErrorException $e) {
                    throw new \Exception("Stripe API error: " . $e->getMessage());
                }
                
                // Update local database
                $subscription->plan_id = $newPlan->id;
                $subscription->save();
                
                // Handle tokens if token allocation is different
                if ($newPlan->tokens_per_cycle != $oldPlan->tokens_per_cycle) {
                    // Reset subscription tokens
                    $user = $subscription->user;
                    $oldRemainingTokens = $user->subscription_token;
                    $user->subscription_token = 0;
                    $user->save();
                    
                    // Check if this is a Free plan and user has had it before
                    $skipTokenAllocation = false;
                    if ($newPlan->price == 0 && $newPlan->name === 'Free') {
                        // Check if user has previously had a Free plan subscription
                        $previousFreePlanSub = Subscription::where('user_id', $user->id)
                            ->where('id', '!=', $subscription->id) // Exclude current subscription
                            ->whereHas('plan', function($query) {
                                $query->where('name', 'Free')->where('price', 0);
                            })
                            ->exists();
                        
                        // If user has previously had a Free plan, skip token allocation
                        if ($previousFreePlanSub) {
                            Log::info("User {$user->id} has previously had a Free plan subscription. Skipping Free plan token allocation.");
                            $skipTokenAllocation = true;
                        }
                    }
                    
                    // Only allocate tokens if we're not skipping allocation
                    if (!$skipTokenAllocation) {
                        // Allocate new plan's tokens
                        $purchaseData = [
                            'sessionId' => 'plan-change-' . $subscription->id . '-' . now()->timestamp,
                            'priceId' => $newPlan->stripe_price_id,
                            'amount' => $newPlan->price,
                            'status' => 'completed',
                            'customerEmail' => $user->email,
                            'currency' => $newPlan->currency ?? 'usd',
                            'type' => 'plan_change'
                        ];
                        
                        $this->tokenService->updateUserTokens(
                            $user->id, 
                            $newPlan->tokens_per_cycle,
                            $purchaseData,
                            'subscription_token'
                        );
                    } else {
                        Log::info("Skipped token allocation for Free plan as user {$user->id} has already received Free plan tokens before.");
                    }
                }
                
                $message = "Your subscription has been changed to the {$newPlan->name} plan."; 
            }
            
            DB::commit();
            
            return [
                'success' => true,
                'message' => $message,
                'data' => $subscription,
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error changing subscription plan: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to change subscription plan: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Get subscriptions that are due for renewal.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSubscriptionsDueForRenewal()
    {
        return Subscription::where('status', 'active')
            ->where('next_billing_date', '<=', now())
            ->whereNull('canceled_at')
            ->orWhere(function($query) {
                $query->where('status', 'active')
                    ->where('next_billing_date', '<=', now())
                    ->whereNotNull('canceled_at')
                    ->where('ends_at', '>', now());
            })
            ->with(['user', 'plan'])
            ->get();
    }
    
    /**
     * Award tokens for a subscription based on the plan.
     *
     * @param Subscription $subscription
     * @return bool
     */
    protected function awardTokensForSubscription(Subscription $subscription)
    {
        try {
            $plan = $subscription->plan;
            $user = $subscription->user;
            
            if (!$plan || !$user || $plan->tokens_per_cycle <= 0) {
                return false;
            }
            
            // Check if this is a Free plan (identified by price = 0 and name = 'Free')
            if ($plan->price == 0 && $plan->name === 'Free') {
                // Check if user has previously had a Free plan subscription
                $previousFreePlanSub = Subscription::where('user_id', $user->id)
                    ->where('id', '!=', $subscription->id) // Exclude current subscription
                    ->whereHas('plan', function($query) {
                        $query->where('name', 'Free')->where('price', 0);
                    })
                    ->exists();
                
                // If user has previously had a Free plan, skip token allocation
                if ($previousFreePlanSub) {
                    Log::info("User {$user->id} has previously had a Free plan subscription. Skipping Free plan token allocation.");
                    return true; // Return true but don't award tokens
                }
            }
            
            // Create purchase data
            $purchaseData = [
                'sessionId' => 'subscription-' . $subscription->id . '-' . now()->timestamp,
                'priceId' => $plan->stripe_price_id ?? ('plan-' . $plan->id),
                'amount' => $plan->price,
                'status' => 'completed',
                'customerEmail' => $user->email,
                'currency' => $plan->currency,
                'type' => 'subscription'
            ];
            
            // Update user tokens
            return $this->tokenService->updateUserTokens(
                $user->id,
                $plan->tokens_per_cycle,
                $purchaseData
            );
        } catch (\Exception $e) {
            Log::error('Error awarding tokens for subscription: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle a newly created subscription from Stripe webhook.
     *
     * @param string $userId
     * @param string $planId
     * @param string $stripeSubscriptionId
     * @param string|null $nextBillingDate
     * @param string $status
     * @return Subscription|null
     */
    public function handleSubscriptionCreated($userId, $planId, $stripeSubscriptionId, $nextBillingDate = null, $status = 'active')
    {
        try {
            // Find the plan
            $plan = Plan::find($planId);
            if (!$plan) {
                Log::error("Plan not found: {$planId}");
                return null;
            }
            
            // Create subscription
            $subscription = new Subscription([
                'user_id' => $userId,
                'plan_id' => $planId,
                'stripe_subscription_id' => $stripeSubscriptionId,
                'status' => $status,
                'next_billing_date' => $nextBillingDate
            ]);
            $subscription->save();
            
            // check if plan is free plan, if is free plan update user free_plan_used
            $plan = Plan::find($planId);
            if ($plan->name == 'Free') {
                User::where('id', $userId)->update(['free_plan_used' => true]);
            }
            // Award initial tokens if status is active or trialing
            if (in_array($status, ['active', 'trialing'])) {
                $this->awardTokensForSubscription($subscription);
            }
            
            return $subscription;
        } catch (\Exception $e) {
            Log::error('Failed to create subscription from webhook: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Handle a subscription update from Stripe webhook.
     *
     * @param string $stripeSubscriptionId
     * @param string $status
     * @param string|null $nextBillingDate
     * @param string|null $cancelAt
     * @param int|null $planId The new plan ID if the plan has changed
     * @return bool
     */
    public function handleSubscriptionUpdated($stripeSubscriptionId, $status, $nextBillingDate = null, $cancelAt = null, $planId = null)
    {
        try {
            // EMERGENCY DEBUG: Write directly to a file we can check
            $debugLog = "\nhandleSubscriptionUpdated called at " . date('Y-m-d H:i:s') . "\n";
            $debugLog .= "Params: stripeSubId={$stripeSubscriptionId}, status={$status}, planId={$planId}\n";
            file_put_contents(base_path('subscription_service_debug.txt'), $debugLog, FILE_APPEND);
            
            Log::info("Looking for subscription with Stripe ID: {$stripeSubscriptionId}");
            
            // First, try exact matching on stripe_subscription_id
            $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            
            // If not found with exact match, try to find by substring match (in case there are format differences)
            if (!$subscription) {
                Log::warning("Subscription not found with exact match, trying substring search");
                $subscription = Subscription::whereRaw('LOWER(stripe_subscription_id) LIKE ?', ['%' . strtolower($stripeSubscriptionId) . '%'])->first();
            }
            
            if (!$subscription) {
                // For debugging - list all subscription IDs in the database
                $allSubscriptions = Subscription::all(['id', 'stripe_subscription_id']);
                Log::error("Subscription not found with Stripe ID: {$stripeSubscriptionId}. Available subscriptions: " . json_encode($allSubscriptions));
                return false;
            }
            
            // Log before state
            Log::info("Found subscription ID {$subscription->id} for user {$subscription->user_id}.");
            Log::info("Before update: status={$subscription->status}, next_billing_date={$subscription->next_billing_date}, canceled_at={$subscription->canceled_at}, plan_id={$subscription->plan_id}");
            
            // Update subscription details
            $subscription->status = $status;
            
            if ($nextBillingDate) {
                $subscription->next_billing_date = $nextBillingDate;
            }
            
            if ($cancelAt) {
                $subscription->canceled_at = $cancelAt; 
                $subscription->ends_at = $cancelAt;
            }
            
            // Update plan ID if provided
            if ($planId) {
                // Get the old and new plans
                $oldPlanId = $subscription->plan_id;
                $oldPlan = \App\Models\Plan::find($oldPlanId);
                $newPlan = \App\Models\Plan::find($planId);
                
                if ($oldPlanId != $planId) {
                    Log::info("PLAN CHANGE DETECTED: Updating plan_id from {$oldPlanId} to {$planId}");
                    
                    // Force update of plan_id
                    $subscription->plan_id = $planId;
                    
                    // EMERGENCY DEBUG: Log the subscription state before update
                    $debugLog = "\nBefore update in DB at " . date('Y-m-d H:i:s') . "\n";
                    $debugLog .= "Subscription ID: {$subscription->id}, Old Plan ID: {$oldPlanId}, New Plan ID: {$planId}\n";
                    $debugLog .= "Dumping subscription: " . json_encode($subscription->toArray()) . "\n";
                    file_put_contents(base_path('db_update_debug.txt'), $debugLog, FILE_APPEND);
                    
                    // Ensure the update is persisted with direct SQL for debugging
                    try {
                        // First try using Query Builder
                        $updateResult = DB::table('subscriptions')
                            ->where('id', $subscription->id)
                            ->update(['plan_id' => $planId]);
                            
                        $debugLog = "Query Builder update result: {$updateResult} row(s) affected\n";
                        
                        // Then try using direct SQL for absolute certainty
                        $sql = "UPDATE subscriptions SET plan_id = ? WHERE id = ?";
                        $rawResult = DB::statement($sql, [$planId, $subscription->id]);
                        
                        $debugLog .= "Raw SQL update result: " . ($rawResult ? 'SUCCESS' : 'FAILED') . "\n";
                        file_put_contents(base_path('db_update_debug.txt'), $debugLog, FILE_APPEND);
                    } catch (\Exception $e) {
                        $errorMsg = "DB update error: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
                        file_put_contents(base_path('db_update_debug.txt'), $errorMsg, FILE_APPEND);
                    }
                    
                    Log::info("Direct DB update attempts completed");
                    
                    // If both plans are found and the subscription is active, adjust tokens
                    if ($oldPlan && $newPlan && ($subscription->status === 'active' || $subscription->status === 'trialing')) {
                        $tokenDifference = $newPlan->tokens_per_cycle - $oldPlan->tokens_per_cycle;
                        
                        if ($tokenDifference != 0) {
                            $user = $subscription->user;
                            
                            if ($user) {
                                Log::info("Adjusting tokens for user {$user->id} due to plan change. Difference: {$tokenDifference}");
                                
                                // Create purchase data for token history
                                $purchaseData = [
                                    'type' => 'plan_change',
                                    'status' => 'completed',
                                    'currency' => $newPlan->currency ?? 'usd',
                                    'amount' => abs($newPlan->price - $oldPlan->price),
                                    'customerEmail' => $user->email,
                                    'priceId' => $newPlan->stripe_price_id
                                ];
                                
                                // Update user tokens (handles both positive and negative adjustments)
                                $this->tokenService->updateUserTokens(
                                    $user->id,
                                    $tokenDifference,
                                    $purchaseData
                                );
                                
                                Log::info("User tokens adjusted. Old plan had {$oldPlan->tokens_per_cycle} tokens, new plan has {$newPlan->tokens_per_cycle} tokens.");
                            } else {
                                Log::error("Could not find user for subscription ID: {$subscription->id}");
                            }
                        } else {
                            Log::info("No token adjustment needed. Both plans have the same token allocation.");
                        }
                    } else {
                        if (!$oldPlan || !$newPlan) {
                            Log::warning("Could not find either old plan (ID: {$oldPlanId}) or new plan (ID: {$planId})");
                        } else if ($subscription->status !== 'active' && $subscription->status !== 'trialing') {
                            Log::info("Skipping token adjustment for non-active subscription. Status: {$subscription->status}");
                        }
                    }
                } else {
                    // Plan ID provided but it's the same as the current one
                    Log::info("Plan ID unchanged: {$planId}");
                }
            }
            
            $saved = $subscription->save();
            
            // Log after state
            Log::info("After update: status={$subscription->status}, next_billing_date={$subscription->next_billing_date}, canceled_at={$subscription->canceled_at}, plan_id={$subscription->plan_id}");
            Log::info("Database save result: " . ($saved ? 'SUCCESS' : 'FAILED'));
            
            // Double-check that the plan_id was actually updated in the database
            $refreshed = Subscription::find($subscription->id);
            Log::info("Verified plan_id after refresh: {$refreshed->plan_id} (expected: {$planId})");
            
            return $saved;
        } catch (\Exception $e) {
            Log::error('Failed to update subscription from webhook: ' . $e->getMessage());
            Log::error('Exception stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }
    
    /**
     * Handle a subscription cancellation from Stripe webhook.
     *
     * @param string $stripeSubscriptionId
     * @param string $cancellationDate
     * @return bool
     */
    public function handleSubscriptionCancelled($stripeSubscriptionId, $cancellationDate)
    {
        try {
            $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            
            if (!$subscription) {
                Log::error("Subscription not found with Stripe ID: {$stripeSubscriptionId}");
                return false;
            }
            
            // Update subscription
            $subscription->status = 'canceled';
            $subscription->canceled_at = $cancellationDate;
            $subscription->ends_at = $cancellationDate;
            $subscription->save();
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to cancel subscription from webhook: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle a successful payment for a subscription.
     *
     * @param string $stripeSubscriptionId
     * @param string $invoiceId
     * @param float $amount
     * @param string $currency
     * @return bool
     */
    public function handleSuccessfulPayment($stripeSubscriptionId, $invoiceId, $amount, $currency = 'usd')
    {
        try {
            $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            
            if (!$subscription) {
                Log::error("Subscription not found with Stripe ID: {$stripeSubscriptionId}");
                return false;
            }
            
            // Calculate next billing date based on the interval
            $currentBillingDate = $subscription->next_billing_date ?: now();
            $nextBillingDate = null;
            
            // Update next billing date based on plan interval
            if ($subscription->plan->interval === 'monthly') {
                $nextBillingDate = now()->addMonth();
            } elseif ($subscription->plan->interval === 'yearly') {
                $nextBillingDate = now()->addYear();
            } else {
                // Default to monthly if interval is not recognized
                $nextBillingDate = now()->addMonth();
            }
            
            // Update subscription
            $subscription->status = 'active';
            $subscription->next_billing_date = $nextBillingDate;
            $subscription->save();
            
            // Record payment as a purchase for accounting
            $user = $subscription->user;
            $plan = $subscription->plan;
            
            if ($user && $plan) {
                // Create purchase record
                $purchase = new \App\Models\Purchase([
                    'user_id' => $user->id,
                    'sessionId' => $invoiceId,
                    'priceId' => $plan->stripe_price_id, // Use stripe_price_id if available
                    'amount' => $amount,
                    'currency' => $currency,
                    'status' => 'paid',
                    'type' => 'subscription', // Mark as subscription type
                    'description' => "Subscription payment for {$plan->name}"
                ]);
                $purchase->save();
                
                // Award tokens
                $this->awardTokensForSubscription($subscription);
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to handle successful payment: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle a failed payment for a subscription.
     * 
     * @param string $stripeSubscriptionId
     * @param string $invoiceId
     * @param int $attemptCount
     * @return bool
     */
    public function handleFailedPayment($stripeSubscriptionId, $invoiceId, $attemptCount)
    {
        try {
            $subscription = Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            
            if (!$subscription) {
                Log::error("Subscription not found with Stripe ID: {$stripeSubscriptionId}");
                return false;
            }
            
            // If it's the first attempt, we might just log it
            // If multiple failed attempts, mark the subscription as past_due
            if ($attemptCount > 1) {
                $subscription->status = 'past_due';
                $subscription->save();
                
                // You might want to send an email to the user here
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('Failed to handle failed payment: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Resume a canceled subscription that hasn't ended yet.
     *
     * @param Subscription $subscription The subscription to resume
     * @return array Response with success/failure information
     */
    public function resumeSubscription(Subscription $subscription): array
    {
        try {
            // Check if this subscription can be resumed
            if ($subscription->status !== 'canceled') {
                return [
                    'success' => false,
                    'message' => 'This subscription cannot be resumed. Only canceled subscriptions can be resumed.'
                ];
            }
            
            // If ends_at is set and in the past, don't allow resume
            if ($subscription->ends_at !== null && $subscription->ends_at->isPast()) {
                return [
                    'success' => false,
                    'message' => 'This subscription has already ended and cannot be resumed. Please create a new subscription.'
                ];
            }
            
            // Check if the end date is in the future
            if ($subscription->ends_at->isPast()) {
                return [
                    'success' => false,
                    'message' => 'This subscription has already ended and cannot be resumed. Please create a new subscription.'
                ];
            }
            
            // Resume the subscription
            $subscription->status = 'active';
            $subscription->canceled_at = null;
            $subscription->ends_at = null;
            $subscription->save();
            
            // If this is a Stripe subscription, we should also resume it in Stripe
            // Temporarily commented out for testing without Stripe integration
            /*
            if ($subscription->stripe_subscription_id) {
                $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));
                try {
                    $stripe->subscriptions->update(
                        $subscription->stripe_subscription_id,
                        ['cancel_at_period_end' => false]
                    );
                } catch (\Exception $e) {
                    Log::warning("Failed to resume Stripe subscription, but local subscription was resumed: " . $e->getMessage());
                }
            }
            */
            
            return [
                'success' => true,
                'message' => 'Subscription resumed successfully',
                'subscription' => $subscription
            ];
            
        } catch (\Exception $e) {
            Log::error("Error resuming subscription: " . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Failed to resume subscription: ' . $e->getMessage()
            ];
        }
    }
}

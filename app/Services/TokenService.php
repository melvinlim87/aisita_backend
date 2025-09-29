<?php

namespace App\Services;

use App\Models\User;
use App\Models\Purchase;
use App\Models\Referral;
use App\Models\TokenUsage;
use App\Services\ReferralService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class TokenService
{
    /**
     * The referral service instance.
     *
     * @var \App\Services\ReferralService
     */
    protected $referralService;
    
    /**
     * Create a new service instance.
     *
     * @param  \App\Services\ReferralService  $referralService
     * @return void
     */
    public function __construct(ReferralService $referralService)
    {
        $this->referralService = $referralService;
    }
    
    /**
     * Update a user's token balance and record the purchase
     *
     * @param string $userId
     * @param int $tokensToAdd
     * @param array $purchaseData
     * @param string $tokenType - 'subscription_token', 'registration_token', 'free_token' or 'addons_token'
     * @return bool
     */
    public function updateUserTokens(string $userId, int $tokensToAdd, array $purchaseData, string $tokenType = 'subscription_token'): bool
    {
        try {
            Log::info("Starting updateUserTokens for user: {$userId}, adding {$tokensToAdd} tokens");
            Log::info("Purchase data: " . json_encode($purchaseData));
            DB::beginTransaction();
            
            // Find the user by Laravel ID only
            $user = User::find($userId);
            
            if (!$user) {
                Log::error("User not found: {$userId}");
                DB::rollBack();
                return false;
            }
            
            // Get current token balances
            $subscriptionTokens = $user->subscription_token;
            $addonsTokens = $user->addons_token;
            
            Log::info("Found user: {$user->id}, name: {$user->name}, email: {$user->email}, current subscription_token: {$subscriptionTokens}, current addons_token: {$addonsTokens}");
            
            // Update the appropriate token type
            switch ($tokenType) {
                case 'subscription_token':
                    $previousTokens = $user->subscription_token;
                    $user->subscription_token += $tokensToAdd;
                    Log::info("Updating subscription_token from {$previousTokens} to {$user->subscription_token} (difference: {$tokensToAdd})");
                    break;
                case 'registration_token':
                    $previousTokens = $user->registration_token;
                    $user->registration_token += $tokensToAdd;
                    Log::info("Updating registration_token from {$previousTokens} to {$user->registration_token} (difference: {$tokensToAdd})");
                    break;
                case 'free_token':
                    $previousTokens = $user->free_token;
                    $user->free_token += $tokensToAdd;
                    Log::info("Updating free_token from {$previousTokens} to {$user->free_token} (difference: {$tokensToAdd})");
                    break;
                case 'addons_token':
                default:
                    $previousTokens = $user->addons_token;
                    $user->addons_token += $tokensToAdd;
                    Log::info("Updating addons_token from {$previousTokens} to {$user->addons_token} (difference: {$tokensToAdd})");
                    break;
            }
            
            // Explicitly check if save is successful
            $saveResult = $user->save();
            if ($saveResult) {
                Log::info("User tokens updated successfully. Save result: TRUE");
                
                // Double-check that the tokens were actually saved by re-fetching the user
                $refreshedUser = User::find($userId);
                if ($refreshedUser) {
                    if ($tokenType === 'subscription_token') {
                        Log::info("Verified token update: User {$userId} now has {$refreshedUser->subscription_token} subscription tokens");
                        
                        // Check if the token amount is what we expected
                        if ($refreshedUser->subscription_token != $user->subscription_token) {
                            Log::warning("Token mismatch! Expected {$user->subscription_token} but found {$refreshedUser->subscription_token}");
                        }
                    } else {
                        Log::info("Verified token update: User {$userId} now has {$refreshedUser->addons_token} addon tokens");
                        
                        // Check if the token amount is what we expected
                        if ($refreshedUser->addons_token != $user->addons_token) {
                            Log::warning("Token mismatch! Expected {$user->addons_token} but found {$refreshedUser->addons_token}");
                        }
                    }
                }
            } else {
                Log::error("User->save() returned FALSE when updating tokens!");
                DB::rollBack();
                return false;
            }
            
            // Create purchase record
            $purchase = [
                'user_id' => $user->id,
                'price_id' => $purchaseData['priceId'] ?? null,
                'amount' => $purchaseData['amount'] ?? 0,
                'tokens' => $tokensToAdd,
                'status' => $purchaseData['status'] ?? 'completed',
                'customer_email' => $purchaseData['customerEmail'] ?? null,
                'currency' => $purchaseData['currency'] ?? 'usd',
                'type' => $purchaseData['type'] ?? 'purchase',
                'tokens_awarded' => 0
            ];
            
            // Set 1-year expiration date for addon tokens (top-up credits)
            if ($tokenType === 'addons_token') {
                // Check if there's an explicit expires_at in the purchase data
                if (isset($purchaseData['expires_at'])) {
                    $purchase['expires_at'] = $purchaseData['expires_at'];
                } else {
                    // Set default 1-year expiration from purchase date
                    $purchase['expires_at'] = now()->addYear()->toDateTimeString();
                }
                
                Log::info("Setting 1-year expiration for addon tokens: {$purchase['expires_at']}");
            }
            
            // Handle the session_id - this field cannot be null in the database
            if (isset($purchaseData['sessionId']) && $purchaseData['sessionId'] !== null) {
                $purchase['session_id'] = $purchaseData['sessionId'];
            } else {
                // Generate a placeholder session ID for webhook events like plan changes
                $eventType = $purchaseData['type'] ?? 'purchase';
                $purchase['session_id'] = $eventType . '-' . uniqid() . '-' . time();
                Log::info("Generated placeholder session_id: {$purchase['session_id']} for {$eventType} event");
            }
            
            Log::info("Creating purchase record: " . json_encode($purchase));
            $newPurchase = Purchase::create($purchase);
            Log::info("Purchase record created with ID: {$newPurchase->id}");
            
            // Every time purchase will add token to referred
            // // Check if this is the user's first purchase and if they were referred
            // $purchaseCount = Purchase::where('user_id', $user->id)
            //     ->where('type', 'subscription')
            //     ->count();
            
                        
            // Check if got referral
            $referral = Referral::where('referred_id', $user->id)->first();
                
            // Referral will always get 20% from the purchased tokens
            if ($referral) {
                Log::info("Found unconverted referral for user: {$user->id}");
                
                // Convert the referral and award tokens to the referrer
                // award tokens will be 20% of plans or credit tokens purchased
                $awardedTokens = (int)number_format(($tokensToAdd * 0.2), 0, '.', "");
                Log::info("Awarded Tokens : ". $awardedTokens);
                $currentPurchase = Purchase::find($newPurchase->id);
                Log::info("Current Purchase : ". json_encode($currentPurchase));
                $currentPurchase->update([
                    'referrer_id' => $referral->referrer_id,
                    'tokens_awarded' => $currentPurchase->tokens_awarded + $awardedTokens,
                ]);
                $referralResult = $this->referralService->convertReferral($user, $awardedTokens);
                
                if ($referralResult['success']) {
                    Log::info("Successfully converted referral");
                } else {
                    Log::warning("Failed to convert referral: {$referralResult['message']}");
                }
            }
            
            // If got referral, update Referral tokens_awarded
            
            
            DB::commit();
            Log::info("Transaction committed successfully");
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error updating user tokens: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }
    
    /**
     * Get a user's token balances
     *
     * @param string $userId
     * @return array
     */
    public function getUserTokens(string $userId): array
    {
        $user = User::find($userId);
        
        if (!$user) {
            return [
                'registration_token' => 0,
                'free_token' => 0,
                'subscription_token' => 0,
                'addons_token' => 0,
                'total' => 0
            ];
        }
        
        $total = $user->registration_token + $user->free_token + $user->subscription_token + $user->addons_token;
        
        return [
            'registration_token' => $user->registration_token,
            'free_token' => $user->free_token,
            'subscription_token' => $user->subscription_token,
            'addons_token' => $user->addons_token,
            'total' => $total
        ];
    }
    
    /**
     * Deduct tokens from a user's balance
     *
     * @param string $userId
     * @param int $tokensToDeduct
     * @param string $reason
     * @param string $tokenType The type of token to deduct from first ('registration_token', 'free_token', 'subscription_token' or 'addons_token')
     * @param string|null $model The model used for the operation
     * @param string|null $analysisType The type of analysis performed
     * @param int|null $inputTokens The number of input tokens used
     * @param int|null $outputTokens The number of output tokens used
     * @return bool
     */
    public function deductUserTokens(string $userId, int $tokensToDeduct, string $reason = 'usage', string $tokenType = 'subscription_token', ?string $model = null, ?string $analysisType = null, ?int $inputTokens = null, ?int $outputTokens = null): bool
    {
        try {
            DB::beginTransaction();
            
            // Find the user by Laravel ID only
            $user = User::find($userId);
            
            if (!$user) {
                Log::error("User not found when deducting tokens: {$userId}");
                DB::rollBack();
                return false;
            }
            
            // Get current token balances
            $registrationTokens = $user->registration_token;
            $freeTokens = $user->free_token;
            $subscriptionTokens = $user->subscription_token;
            $addonTokens = $user->addons_token;
            $totalTokens = $registrationTokens + $freeTokens + $subscriptionTokens + $addonTokens;
            
            // Check if user has enough tokens in total
            if ($totalTokens < $tokensToDeduct) {
                Log::warning("Insufficient total tokens for user {$userId}: has {$totalTokens} total ({$registrationTokens} registration + {$freeTokens} free + {$subscriptionTokens} subscription + {$addonTokens} addon), needs {$tokensToDeduct}");
                DB::rollBack();
                return false;
            }
            
            // Track which token types were used
            $registrationTokensUsed = 0;
            $freeTokensUsed = 0;
            $subscriptionTokensUsed = 0;
            $addonTokensUsed = 0;
            $tokenTypeUsed = 'mixed';
            $remainingToDeduct = $tokensToDeduct;
            
            // Token deduction priority: registration tokens -> free tokens -> SUBSCRIPTION TOKENS -> addon tokens
            // This ensures subscription tokens are consumed before addon tokens (top-ups)
            
            // 1. First use registration tokens if available
            if ($registrationTokens > 0 && $remainingToDeduct > 0) {
                $registrationTokensUsed = min($registrationTokens, $remainingToDeduct);
                $user->registration_token -= $registrationTokensUsed;
                $remainingToDeduct -= $registrationTokensUsed;
                
                if ($remainingToDeduct === 0) {
                    $tokenTypeUsed = 'registration_token';
                }
            }
            
            // 2. Then use free tokens if available and needed
            if ($freeTokens > 0 && $remainingToDeduct > 0) {
                $freeTokensUsed = min($freeTokens, $remainingToDeduct);
                $user->free_token -= $freeTokensUsed;
                $remainingToDeduct -= $freeTokensUsed;
                
                if ($remainingToDeduct === 0 && $registrationTokensUsed === 0) {
                    $tokenTypeUsed = 'free_token';
                } else if ($registrationTokensUsed > 0) {
                    $tokenTypeUsed = 'mixed';
                }
            }
            
            // 3. CHANGED PRIORITY: Use subscription tokens first before addons_token
            if ($subscriptionTokens > 0 && $remainingToDeduct > 0) {
                $subscriptionTokensUsed = min($subscriptionTokens, $remainingToDeduct);
                $user->subscription_token -= $subscriptionTokensUsed;
                $remainingToDeduct -= $subscriptionTokensUsed;
                
                if ($remainingToDeduct === 0 && $registrationTokensUsed === 0 && $freeTokensUsed === 0) {
                    $tokenTypeUsed = 'subscription_token';
                } else if ($registrationTokensUsed > 0 || $freeTokensUsed > 0) {
                    $tokenTypeUsed = 'mixed';
                }
            }
            
            // 4. Only use addon tokens after all subscription tokens are exhausted
            if ($remainingToDeduct > 0) {
                $addonTokensUsed = $remainingToDeduct;
                $user->addons_token -= $addonTokensUsed;
                
                if ($registrationTokensUsed === 0 && $freeTokensUsed === 0 && $subscriptionTokensUsed === 0) {
                    $tokenTypeUsed = 'addons_token';
                } else {
                    $tokenTypeUsed = 'mixed';
                }
            }
            
            Log::info("Token deduction breakdown for user {$userId}: {$registrationTokensUsed} from registration, {$freeTokensUsed} from free, {$subscriptionTokensUsed} from subscription, {$addonTokensUsed} from addons, total: {$tokensToDeduct}");
            
            $user->save();
            
            // Record usage analytics in the token_usage table only
            // Parse the reason string to extract feature if not explicitly provided
            $feature = $reason;
            
            // Use provided parameters if available, otherwise extract from reason
            if ($model === null) {
                // Try to determine model from the reason string if not provided
                if (strpos($reason, 'gpt-4') !== false) {
                    $model = 'gpt-4';
                } elseif (strpos($reason, 'gpt-3') !== false) {
                    $model = 'gpt-3.5-turbo';
                } elseif (strpos($reason, 'gemini') !== false) {
                    $model = 'gemini-pro';
                }
            }
            
            if ($analysisType === null) {
                // Try to determine analysis type from the reason string if not provided
                if (strpos($reason, 'image_analysis') !== false) {
                    $feature = 'image_analysis';
                    $analysisType = 'vision';
                } elseif (strpos($reason, 'chat_completion') !== false) {
                    $feature = 'chat_completion';
                    $analysisType = 'text';
                }
            }
            
            // Use provided token counts if available, otherwise use estimation
            if ($inputTokens === null || $outputTokens === null) {
                // Fall back to estimation if actual counts aren't provided
                $inputTokens = (int)($tokensToDeduct * 0.2); // assume 20% input
                $outputTokens = (int)($tokensToDeduct * 0.8); // assume 80% output
                
                Log::info('Using estimated token counts', [
                    'user_id' => $userId,
                    'estimated_input' => $inputTokens,
                    'estimated_output' => $outputTokens,
                    'total' => $tokensToDeduct
                ]);
            } else {
                Log::info('Using actual token counts', [
                    'user_id' => $userId,
                    'actual_input' => $inputTokens,
                    'actual_output' => $outputTokens,
                    'total' => $inputTokens + $outputTokens
                ]);
            }
            
            // Calculate the actual total (should be sum of input and output)
            $actualTotal = $inputTokens + $outputTokens;
            
            // Check if there's a large discrepancy between tokensToDeduct and actualTotal
            if (abs($actualTotal - $tokensToDeduct) > 10 && $actualTotal > 0) {
                Log::warning('Token count discrepancy detected', [
                    'user_id' => $userId,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens, 
                    'actual_total' => $actualTotal,
                    'tokens_deducted' => $tokensToDeduct,
                    'difference' => $actualTotal - $tokensToDeduct
                ]);
            }
            
            TokenUsage::create([
                'user_id' => $user->id,
                'feature' => $feature,
                'model' => $model,
                'analysis_type' => $analysisType,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'tokens_used' => $tokensToDeduct, // What was actually deducted from user balance
                'total_tokens' => $actualTotal,   // Actual sum of input and output tokens
                'timestamp' => Carbon::now()
            ]);
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error deducting user tokens: " . $e->getMessage());
            return false;
        }
    }
}

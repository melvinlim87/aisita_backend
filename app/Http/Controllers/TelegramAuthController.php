<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Support\Facades\Log;

class TelegramAuthController extends Controller
{
    /**
     * The referral service instance.
     *
     * @var \App\Services\ReferralService
     */
    protected $referralService;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\ReferralService  $referralService
     * @return void
     */
    public function __construct(ReferralService $referralService)
    {
        $this->referralService = $referralService;
    }

    /**
     * Register a new user via Telegram
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $request->validate([
            'telegram_id' => 'required|string|unique:users,telegram_id',
            'name' => 'required|string|max:255',
            'username' => 'nullable|string|max:255',
            'email' => 'nullable|string|email|max:255|unique:users',
            'referral_code' => 'nullable|string|max:255',
        ]);

        // Generate a random password for security
        $password = Str::random(32);

        // Create the user
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'telegram_id' => $request->telegram_id,
            'telegram_username' => $request->username,
            'password' => Hash::make($password),
            'subscription_token' => 0,
            'registration_token' => 4000, // Award 4000 free tokens for Telegram registration (enough for 1 full image analysis)
            'free_token' => 0,
            'addons_token' => 0,
            'role_id' => 1, // Default role ID
        ]);

        // Automatically generate a referral code for the new user
        $generatedReferralCode = $this->referralService->generateReferralCode($user);
        Log::info('Generated referral code for new Telegram user', [
            'user_id' => $user->id,
            'referral_code' => $generatedReferralCode
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;
        
        // Process referral if a code was provided
        $referralResult = null;
        if (!empty($request->referral_code)) {
            Log::info('Processing referral for new Telegram user', [
                'user_id' => $user->id,
                'referral_code' => $request->referral_code
            ]);
            
            $referralResult = $this->referralService->processNewUserReferral($user, $request->referral_code);
            
            if ($referralResult['success']) {
                Log::info('Referral processed successfully for Telegram user', [
                    'user_id' => $user->id,
                    'referral' => $referralResult['referral']
                ]);
            } else {
                Log::warning('Failed to process referral for Telegram user', [
                    'user_id' => $user->id,
                    'message' => $referralResult['message']
                ]);
            }
        }

        // Generate a shareable link
        $appUrl = config('app.url', 'http://localhost:8000');
        $shareableLink = $appUrl . '/register/' . $user->referral_code;
        
        // Record the free token award in history
        \App\Models\TokenHistory::create([
            'user_id' => $user->id,
            'amount' => 4000,
            'action' => 'credited',
            'reason' => 'Telegram registration bonus',
            'balance_after' => 4000,
        ]);
        
        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'telegram_id' => $user->telegram_id,
            'telegram_username' => $user->telegram_username,
            'referral_code' => $user->referral_code,
            'shareable_link' => $shareableLink,
            'referral_applied' => $referralResult ? $referralResult['success'] : null,
            'referral_message' => $referralResult ? $referralResult['message'] : null,
            'free_tokens_awarded' => 10
        ], 201);
    }

    /**
     * Verify Telegram authentication data
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verify(Request $request)
    {
        $request->validate([
            'auth_data' => 'required|string',
            'bot_token' => 'required|string',
        ]);

        try {
            // Decode the auth data
            $authData = json_decode($request->auth_data, true);
            
            if (!$authData || !isset($authData['id']) || !isset($authData['hash'])) {
                return response()->json(['message' => 'Invalid authentication data'], 400);
            }
            
            // Check if the hash is valid (simplified - in production you'd verify the hash properly)
            $telegramBotToken = $request->bot_token;
            $checkHash = $this->checkTelegramHash($authData, $telegramBotToken);
            
            if (!$checkHash) {
                return response()->json(['message' => 'Invalid hash'], 400);
            }
            
            return response()->json([
                'success' => true,
                'telegram_id' => $authData['id'],
                'first_name' => $authData['first_name'] ?? '',
                'last_name' => $authData['last_name'] ?? '',
                'username' => $authData['username'] ?? '',
                'photo_url' => $authData['photo_url'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('Telegram auth verification error: ' . $e->getMessage());
            return response()->json(['message' => 'Authentication failed: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Check if the provided hash is valid for Telegram authentication
     * 
     * @param array $authData
     * @param string $botToken
     * @return bool
     */
    private function checkTelegramHash($authData, $botToken)
    {
        // In a real implementation, you'd verify the hash properly
        // This is a placeholder implementation
        $dataCheckArr = [];
        foreach ($authData as $key => $value) {
            if ($key !== 'hash') {
                $dataCheckArr[] = $key . '=' . $value;
            }
        }
        sort($dataCheckArr);
        $dataCheckString = implode("\n", $dataCheckArr);
        $secretKey = hash('sha256', $botToken, true);
        $hash = hash_hmac('sha256', $dataCheckString, $secretKey);
        
        return $hash === $authData['hash'];
    }
}

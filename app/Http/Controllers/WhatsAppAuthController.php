<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Support\Facades\Log;

class WhatsAppAuthController extends Controller
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
     * Register a new user via WhatsApp
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string|unique:users,phone_number',
            'name' => 'required|string|max:255',
            'email' => 'nullable|string|email|max:255|unique:users',
            'referral_code' => 'nullable|string|max:255',
        ]);

        // Generate a random password for security
        $password = Str::random(32);

        // Create the user
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'whatsapp_verified' => true,
            'password' => Hash::make($password),
            'subscription_token' => 0,
            'registration_token' => 4000, // Award 4000 free tokens for WhatsApp registration (enough for 1 full image analysis)
            'free_token' => 0,
            'addons_token' => 0,
            'role_id' => 1, // Default role ID
        ]);

        // Automatically generate a referral code for the new user
        $generatedReferralCode = $this->referralService->generateReferralCode($user);
        Log::info('Generated referral code for new WhatsApp user', [
            'user_id' => $user->id,
            'referral_code' => $generatedReferralCode
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;
        
        // Process referral if a code was provided
        $referralResult = null;
        if (!empty($request->referral_code)) {
            Log::info('Processing referral for new WhatsApp user', [
                'user_id' => $user->id,
                'referral_code' => $request->referral_code
            ]);
            
            $referralResult = $this->referralService->processNewUserReferral($user, $request->referral_code);
            
            if ($referralResult['success']) {
                Log::info('Referral processed successfully for WhatsApp user', [
                    'user_id' => $user->id,
                    'referral' => $referralResult['referral']
                ]);
            } else {
                Log::warning('Failed to process referral for WhatsApp user', [
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
            'reason' => 'WhatsApp registration bonus',
            'balance_after' => 4000,
        ]);
        
        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user_id' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'phone_number' => $user->phone_number,
            'referral_code' => $user->referral_code,
            'shareable_link' => $shareableLink,
            'referral_applied' => $referralResult ? $referralResult['success'] : null,
            'referral_message' => $referralResult ? $referralResult['message'] : null,
            'free_tokens_awarded' => 10
        ], 201);
    }

    /**
     * Verify a WhatsApp verification code
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verify(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'verification_code' => 'required|string',
        ]);

        try {
            // In a real implementation, you would verify the code with WhatsApp Business API
            // This is a simplified implementation for demonstration purposes
            
            // For testing, accept any 6-digit code
            if (!preg_match('/^\d{6}$/', $request->verification_code)) {
                return response()->json(['message' => 'Invalid verification code format'], 400);
            }
            
            // In a production environment, you would verify this code against one sent to the phone number
            // For this example, we'll simulate a successful verification
            
            return response()->json([
                'success' => true,
                'phone_number' => $request->phone_number,
                'message' => 'Phone number verified successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('WhatsApp verification error: ' . $e->getMessage());
            return response()->json(['message' => 'Verification failed: ' . $e->getMessage()], 500);
        }
    }
}

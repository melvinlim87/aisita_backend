<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Services\ReferralService;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Illuminate\Support\Facades\Log;

class FirebaseAuthController extends Controller
{
    /**
     * The Firebase Auth instance.
     *
     * @var \Kreait\Firebase\Contract\Auth
     */
    protected $auth;
    
    /**
     * The referral service instance.
     *
     * @var \App\Services\ReferralService
     */
    protected $referralService;

    /**
     * Create a new controller instance.
     *
     * @param  \Kreait\Firebase\Contract\Auth  $auth
     * @param  \App\Services\ReferralService  $referralService
     * @return void
     */
    public function __construct(FirebaseAuth $auth, ReferralService $referralService)
    {
        $this->auth = $auth;
        $this->referralService = $referralService;
    }
    public function login(Request $request)
    {
        $request->validate([
            'idToken' => 'required|string',
            'referral_code' => 'nullable|string|max:255',
        ]);
        
        return $this->processLogin($request, $request->referral_code);
    }
    
    /**
     * Firebase login with a referral code from URL
     *
     * @param Request $request
     * @param string $referralCode
     * @return \Illuminate\Http\JsonResponse
     */
    public function loginWithReferral(Request $request, string $referralCode)
    {
        $request->validate([
            'idToken' => 'required|string',
        ]);
        
        return $this->processLogin($request, $referralCode);
    }
    
    /**
     * Process Firebase login with optional referral code
     *
     * @param Request $request
     * @param string|null $referralCode
     * @return \Illuminate\Http\JsonResponse
     */
    private function processLogin(Request $request, ?string $referralCode = null)
    {
        try {
            // Verify the token using the injected auth service
            $verifiedIdToken = $this->auth->verifyIdToken($request->idToken);
            $firebaseUid = $verifiedIdToken->claims()->get('sub');
            $email = $verifiedIdToken->claims()->get('email');
            $name = $verifiedIdToken->claims()->get('name', $email);

            // Debug log for Firebase UID and claims
            Log::info('Firebase UID Debug', [
                'firebaseUid' => $firebaseUid,
                'claims' => $verifiedIdToken->claims()->all(),
            ]);

            // First check if user exists by email
            $existingUser = User::where('email', $email)->first();
            
            if ($existingUser && empty($existingUser->firebase_uid)) {
                // User exists but doesn't have Firebase UID - update it
                $existingUser->firebase_uid = $firebaseUid;
                $existingUser->save();
                $user = $existingUser;
                Log::info('Updated existing user with Firebase UID', ['user_id' => $user->id]);
            } else {
                // Find or create user by Firebase UID
                // No free tokens for Firebase registration - only Telegram and WhatsApp users get free tokens
                $bonusTokens = 0;
                
                $user = User::firstOrCreate(
                    ['firebase_uid' => $firebaseUid],
                    [
                        'email' => $email,
                        'name' => $name,
                        'password' => Hash::make(Str::random(32)), // random password
                        'subscription_token' => $bonusTokens, // Start with 0 subscription tokens
                        'registration_token' => 0, // No registration tokens for Firebase users
                        'free_token' => 0, // Start with 0 free tokens
                        'addons_token' => 0, // Start with 0 addon tokens
                    ]
                );
                
                // Check if this is a new user
                $isNewUser = $user->wasRecentlyCreated;
                $referralResult = null;
                
                if ($isNewUser) {
                    // Record the free token award in history for new users
                    \App\Models\TokenHistory::create([
                        'user_id' => $user->id,
                        'amount' => $bonusTokens,
                        'action' => 'credited',
                        'reason' => 'New Firebase user registration bonus',
                        'balance_after' => $bonusTokens,
                    ]);
                    
                    Log::info('Awarded bonus tokens to new Firebase user', [
                        'user_id' => $user->id,
                        'bonus_tokens' => $bonusTokens
                    ]);
                    
                    // Automatically generate a referral code for the new user
                    $generatedReferralCode = $this->referralService->generateReferralCode($user);
                    Log::info('Generated referral code for new Firebase user', [
                        'user_id' => $user->id,
                        'referral_code' => $generatedReferralCode
                    ]);
                    
                    // Process referral if a code was provided
                    if (!empty($referralCode)) {
                    Log::info('Processing referral for new Firebase user', [
                        'user_id' => $user->id,
                        'referral_code' => $referralCode,
                        'source' => 'URL parameter'
                    ]);
                    
                    $referralResult = $this->referralService->processNewUserReferral($user, $referralCode);
                    
                    if ($referralResult['success']) {
                        Log::info('Referral processed successfully for Firebase user', [
                            'user_id' => $user->id,
                            'referral' => $referralResult['referral']
                        ]);
                    } else {
                        Log::warning('Failed to process referral for Firebase user', [
                            'user_id' => $user->id,
                            'message' => $referralResult['message']
                        ]);
                    }
                    }
                }
            }

            // Issue Sanctum token
            $token = $user->createToken('auth_token')->plainTextToken;

            $response = [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
                'referral_code' => $user->referral_code,
            ];
            
            // Add referral information to the response if available
            if (isset($isNewUser) && $isNewUser && isset($referralResult)) {
                $response['referral_applied'] = $referralResult['success'];
                $response['referral_message'] = $referralResult['message'];
            }
            
            return response()->json($response);
        } catch (\Exception $e) {
            // Log the specific error for debugging
            \Log::error('Firebase auth error: ' . $e->getMessage());
            
            // Check for specific Firebase errors
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'auth/email-already-in-use') !== false) {
                return response()->json([
                    'message' => 'This email is already registered. Please try signing in with your password.',
                    'error' => 'email-already-in-use',
                    'error_details' => $errorMessage
                ], 409); // Conflict status code
            }
            
            return response()->json([
                'message' => 'Authentication failed',
                'error' => $errorMessage
            ], 401);
        }
    }

    /**
     * Test login endpoint (for development only)
     */
    public function testLogin(Request $request)
    {
        $request->validate([
            'uid' => 'required|string',
            'referral_code' => 'nullable|string|max:255',
        ]);
        
        return $this->processTestLogin($request, $request->referral_code);
    }
    
    /**
     * Test login with a referral code from URL
     *
     * @param Request $request
     * @param string $referralCode
     * @return \Illuminate\Http\JsonResponse
     */
    public function testLoginWithReferral(Request $request, string $referralCode)
    {
        $request->validate([
            'uid' => 'required|string',
        ]);
        
        return $this->processTestLogin($request, $referralCode);
    }
    
    /**
     * Process test login with optional referral code
     *
     * @param Request $request
     * @param string|null $referralCode
     * @return \Illuminate\Http\JsonResponse
     */
    private function processTestLogin(Request $request, ?string $referralCode = null)
    {
        try {
            $uid = $request->uid;
            $user = $this->auth->getUser($uid);
            
            // Get or create the user
            $laravelUser = User::firstOrCreate(
                ['firebase_uid' => $uid],
                [
                    'name' => $user->displayName ?? 'Test User',
                    'email' => $user->email ?? $uid.'@example.com',
                    'password' => Hash::make(Str::random(24)),
                    'tokens' => 0, // Start with 0 tokens
                ]
            );
            
            // Check if this is a new user
            $isNewUser = $laravelUser->wasRecentlyCreated;
            $referralResult = null;
            
            if ($isNewUser) {
                // Automatically generate a referral code for the new user
                $generatedReferralCode = $this->referralService->generateReferralCode($laravelUser);
                Log::info('Generated referral code for new test user', [
                    'user_id' => $laravelUser->id,
                    'referral_code' => $generatedReferralCode
                ]);
                
                // Process referral if a code was provided
                if (!empty($referralCode)) {
                    Log::info('Processing referral for new test user', [
                        'user_id' => $laravelUser->id,
                        'referral_code' => $referralCode,
                        'source' => 'URL parameter'
                    ]);
                    
                    $referralResult = $this->referralService->processNewUserReferral($laravelUser, $referralCode);
                    
                    if ($referralResult['success']) {
                        Log::info('Referral processed successfully for test user', [
                            'user_id' => $laravelUser->id,
                            'referral' => $referralResult['referral']
                        ]);
                    } else {
                        Log::warning('Failed to process referral for test user', [
                            'user_id' => $laravelUser->id,
                            'message' => $referralResult['message']
                        ]);
                    }
                }
            }

            // Update user details if they've changed in Firebase
            if ($user->displayName && $laravelUser->name !== $user->displayName) {
                $laravelUser->name = $user->displayName;
            }
            if ($user->email && $laravelUser->email !== $user->email) {
                $laravelUser->email = $user->email;
            }
            $laravelUser->save();

            // Create a token for this user
            $token = $laravelUser->createToken('auth_token')->plainTextToken;

            $response = [
                'success' => true,
                'access_token' => $token,
                'token_type' => 'bearer',
                'user' => [
                    'uid' => $uid,
                    'email' => $user->email ?? null,
                    'name' => $user->displayName ?? null,
                ],
                'referral_code' => $laravelUser->referral_code
            ];
            
            // Add referral information to the response if available
            if (isset($isNewUser) && $isNewUser && isset($referralResult)) {
                $response['referral_applied'] = $referralResult['success'];
                $response['referral_message'] = $referralResult['message'];
            }
            
            return response()->json($response);
        } catch (\Exception $e) {
            \Log::error('Test login error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Authentication failed',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 401);
        }
    }
}

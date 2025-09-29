<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Referral;
use App\Models\ReferralTier;
use App\Services\BadgeService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\ReferralService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ReferralController extends Controller
{
    /**
     * The referral service instance.
     *
     * @var \App\Services\ReferralService
     */
    protected $referralService;
    
    /**
     * The badge service instance.
     *
     * @var \App\Services\BadgeService
     */
    protected $badgeService;

    /**
     * Create a new controller instance.
     *
     * @param  \App\Services\ReferralService  $referralService
     * @param  \App\Services\BadgeService  $badgeService
     * @return void
     */
    public function __construct(ReferralService $referralService, BadgeService $badgeService)
    {
        $this->referralService = $referralService;
        $this->badgeService = $badgeService;
    }

    /**
     * Generate a referral code for the authenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generateReferralCode(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            $code = $this->referralService->generateReferralCode($user);
            
            // Generate a shareable link
            $appUrl = config('app.url', 'http://localhost:8000');
            $shareableLink = $appUrl . '/register/' . $code;
            
            return response()->json([
                'success' => true,
                'data' => [
                    'referral_code' => $code,
                    'shareable_link' => $shareableLink
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error generating referral code: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error generating referral code',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Apply a referral code for the authenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function applyReferralCode(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'referral_code' => 'required|string|max:255'
            ]);
            
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            // Check if user already has a referral
            if ($user->referred_by) {
                return response()->json([
                    'success' => false,
                    'message' => 'User already has a referral'
                ], 400);
            }
            
            $result = $this->referralService->processNewUserReferral($user, $request->referral_code);
            
            if (!$result['success']) {
                return response()->json($result, 400);
            }
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            Log::error('Error applying referral code: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error applying referral code',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get referrals for the authenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getReferrals(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            $result = $this->referralService->getUserReferrals($user);
            
            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting referrals: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error getting referrals',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Convert a referral for the authenticated user.
     * This would typically be called after a qualifying action (e.g., first purchase).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function convertReferral(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'tokens_to_award' => 'sometimes|integer|min:1'
            ]);
            
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            $customTokens = $request->has('tokens_to_award') ? $request->input('tokens_to_award') : null;
            
            $result = $this->referralService->convertReferral($user, $customTokens);
            
            // Update the badge based on current tier if conversion was successful
            if ($result['success']) {
                $this->badgeService->updateReferralBadge($user);
            }
            
            return response()->json($result, $result['success'] ? 200 : 400);
            
        } catch (\Exception $e) {
            Log::error('Error converting referral: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error converting referral',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get referral tier status for the authenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getReferralStatus(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            $result = $this->referralService->getUserReferralStatus($user);
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            Log::error('Error getting referral status: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error getting referral status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get all badges for the authenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserBadges(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            $result = $this->badgeService->getUserBadges($user);
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            Log::error('Error getting user badges: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error getting user badges',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

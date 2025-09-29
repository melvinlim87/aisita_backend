<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\AffiliateService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AffiliateController extends Controller
{
    /**
     * @var AffiliateService
     */
    protected $affiliateService;

    /**
     * Create a new controller instance.
     *
     * @param AffiliateService $affiliateService
     */
    public function __construct(AffiliateService $affiliateService)
    {
        $this->affiliateService = $affiliateService;
    }

    /**
     * Track a new affiliate sale
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function trackAffiliateSale(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:users,id',
                'subscription_id' => 'required|exists:subscriptions,id',
                'amount' => 'sometimes|numeric|min:0'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Get the affiliate (current user)
            $affiliate = $request->user();
            
            // Get the customer
            $customer = User::find($request->customer_id);
            
            // Get the subscription
            $subscription = Subscription::find($request->subscription_id);
            
            // Determine amount: use request amount if provided, else subscription amount, else 0.00
            $amount = $request->filled('amount') ? (float) $request->input('amount') : ($subscription->amount ?? 0.00);

            // Track the sale
            $result = $this->affiliateService->trackAffiliateSale(
                $affiliate,
                $customer,
                $subscription,
                $amount
            );
            
            return response()->json($result, $result['success'] ? 200 : 400);
            
        } catch (\Exception $e) {
            Log::error('Error tracking affiliate sale: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error tracking affiliate sale',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get affiliate status for the authenticated user
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAffiliateStatus(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            $result = $this->affiliateService->getAffiliateStatus($user);
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            Log::error('Error getting affiliate status: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error getting affiliate status',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get affiliate leaderboard
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getLeaderboard(Request $request): JsonResponse
    {
        try {
            $limit = $request->input('limit', 10);
            $period = $request->input('period', 'all');
            
            // Validate period
            if (!in_array($period, ['all', 'month', 'year'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid period. Must be one of: all, month, year'
                ], 422);
            }
            
            $result = $this->affiliateService->getAffiliateLeaderboard($limit, $period);
            
            return response()->json($result);
            
        } catch (\Exception $e) {
            Log::error('Error getting affiliate leaderboard: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error getting affiliate leaderboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Check for new milestones and award if eligible
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function checkMilestones(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            $result = $this->affiliateService->checkAndAwardMilestone($user);
            
            return response()->json([
                'success' => true,
                'milestone_check' => $result
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error checking milestones: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error checking milestones',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Models\ReferralTier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ReferralTierController extends Controller
{
    /**
     * Display a listing of all referral tiers.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $tiers = ReferralTier::orderBy('min_referrals', 'asc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $tiers
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting referral tiers: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving referral tiers',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Store a newly created referral tier.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'min_referrals' => 'required|integer|min:0',
                'max_referrals' => 'nullable|integer|min:' . ($request->min_referrals ?? 0),
                'referrer_tokens' => 'required|integer|min:0',
                'referee_tokens' => 'required|integer|min:0',
                'badge' => 'required|string|max:255',
                'subscription_reward' => 'nullable|string|in:basic,pro,enterprise',
                'subscription_months' => 'nullable|integer|min:1',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Check if there's an overlap with existing tiers
            $overlap = $this->checkForOverlap(
                $request->min_referrals, 
                $request->max_referrals, 
                null
            );
            
            if ($overlap) {
                return response()->json([
                    'success' => false,
                    'message' => 'This range overlaps with an existing tier',
                    'overlap' => $overlap
                ], 422);
            }
            
            $tier = ReferralTier::create($request->all());
            
            return response()->json([
                'success' => true,
                'message' => 'Referral tier created successfully',
                'data' => $tier
            ], 201);
            
        } catch (\Exception $e) {
            Log::error('Error creating referral tier: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error creating referral tier',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Display the specified referral tier.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $tier = ReferralTier::findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $tier
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting referral tier: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving referral tier',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
    
    /**
     * Update the specified referral tier.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $tier = ReferralTier::findOrFail($id);
            
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'min_referrals' => 'sometimes|required|integer|min:0',
                'max_referrals' => 'nullable|integer|min:' . ($request->min_referrals ?? $tier->min_referrals),
                'referrer_tokens' => 'sometimes|required|integer|min:0',
                'referee_tokens' => 'sometimes|required|integer|min:0',
                'badge' => 'sometimes|required|string|max:255',
                'subscription_reward' => 'nullable|string|in:basic,pro,enterprise',
                'subscription_months' => 'nullable|integer|min:1',
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Check if there's an overlap with existing tiers
            if ($request->has('min_referrals') || $request->has('max_referrals')) {
                $overlap = $this->checkForOverlap(
                    $request->min_referrals ?? $tier->min_referrals,
                    $request->max_referrals ?? $tier->max_referrals,
                    $id
                );
                
                if ($overlap) {
                    return response()->json([
                        'success' => false,
                        'message' => 'This range overlaps with an existing tier',
                        'overlap' => $overlap
                    ], 422);
                }
            }
            
            $tier->update($request->all());
            
            return response()->json([
                'success' => true,
                'message' => 'Referral tier updated successfully',
                'data' => $tier
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error updating referral tier: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating referral tier',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
    
    /**
     * Remove the specified referral tier.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $tier = ReferralTier::findOrFail($id);
            $tier->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Referral tier deleted successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error deleting referral tier: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting referral tier',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
    
    /**
     * Check for overlapping tier ranges
     * 
     * @param int $minReferrals
     * @param int|null $maxReferrals
     * @param int|null $excludeTierId
     * @return \App\Models\ReferralTier|null
     */
    private function checkForOverlap(int $minReferrals, ?int $maxReferrals, ?int $excludeTierId): ?ReferralTier
    {
        $query = ReferralTier::query();
        
        if ($excludeTierId) {
            $query->where('id', '!=', $excludeTierId);
        }
        
        return $query->where(function($query) use ($minReferrals, $maxReferrals) {
            // The new range starts within an existing range
            $query->where(function($q) use ($minReferrals) {
                $q->where('min_referrals', '<=', $minReferrals)
                  ->where(function($inner) use ($minReferrals) {
                      $inner->where('max_referrals', '>=', $minReferrals)
                            ->orWhereNull('max_referrals');
                  });
            });
            
            // Or the new range ends within an existing range
            if ($maxReferrals) {
                $query->orWhere(function($q) use ($maxReferrals) {
                    $q->where('min_referrals', '<=', $maxReferrals)
                      ->where(function($inner) use ($maxReferrals) {
                          $inner->where('max_referrals', '>=', $maxReferrals)
                                ->orWhereNull('max_referrals');
                      });
                });
                
                // Or the new range completely contains an existing range
                $query->orWhere(function($q) use ($minReferrals, $maxReferrals) {
                    $q->where('min_referrals', '>=', $minReferrals)
                      ->where('max_referrals', '<=', $maxReferrals);
                });
            } else {
                // If maxReferrals is null, this is an unbounded range
                // Any tier with min_referrals >= the new min_referrals would overlap
                $query->orWhere('min_referrals', '>=', $minReferrals);
            }
        })->first();
    }
}

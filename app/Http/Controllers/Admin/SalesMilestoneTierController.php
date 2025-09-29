<?php

namespace App\Http\Controllers\Admin;

use App\Models\SalesMilestoneTier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SalesMilestoneTierController extends Controller
{
    /**
     * Display a listing of the sales milestone tiers.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $tiers = SalesMilestoneTier::orderBy('required_sales', 'asc')->get();
            
            return response()->json([
                'success' => true,
                'data' => $tiers
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting sales milestone tiers: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving sales milestone tiers',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Store a newly created sales milestone tier.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'required_sales' => 'required|integer|min:1',
                'badge' => 'required|string|max:255',
                'subscription_reward' => 'nullable|string|in:basic,pro,enterprise',
                'subscription_months' => 'nullable|integer|min:1',
                'cash_bonus' => 'nullable|numeric|min:0',
                'has_physical_plaque' => 'boolean',
                'perks' => 'nullable|string'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // Check for conflicts with existing tiers
            $existingTier = SalesMilestoneTier::where('required_sales', $request->required_sales)->first();
            if ($existingTier) {
                return response()->json([
                    'success' => false,
                    'message' => 'A tier with this required sales count already exists',
                    'existing_tier' => $existingTier
                ], 422);
            }
            
            $tier = SalesMilestoneTier::create($request->all());
            
            return response()->json([
                'success' => true,
                'message' => 'Sales milestone tier created successfully',
                'data' => $tier
            ], 201);
            
        } catch (\Exception $e) {
            Log::error('Error creating sales milestone tier: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error creating sales milestone tier',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Display the specified sales milestone tier.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $tier = SalesMilestoneTier::findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $tier
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error getting sales milestone tier: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving sales milestone tier',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
    
    /**
     * Update the specified sales milestone tier.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $tier = SalesMilestoneTier::findOrFail($id);
            
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'required_sales' => 'sometimes|required|integer|min:1',
                'badge' => 'sometimes|required|string|max:255',
                'subscription_reward' => 'nullable|string|in:basic,pro,enterprise',
                'subscription_months' => 'nullable|integer|min:1',
                'cash_bonus' => 'nullable|numeric|min:0',
                'has_physical_plaque' => 'boolean',
                'perks' => 'nullable|string'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            // If required_sales is being updated, check for conflicts
            if ($request->has('required_sales') && $request->required_sales != $tier->required_sales) {
                $existingTier = SalesMilestoneTier::where('required_sales', $request->required_sales)
                    ->where('id', '!=', $id)
                    ->first();
                    
                if ($existingTier) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A tier with this required sales count already exists',
                        'existing_tier' => $existingTier
                    ], 422);
                }
            }
            
            $tier->update($request->all());
            
            return response()->json([
                'success' => true,
                'message' => 'Sales milestone tier updated successfully',
                'data' => $tier
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error updating sales milestone tier: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating sales milestone tier',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
    
    /**
     * Remove the specified sales milestone tier.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $tier = SalesMilestoneTier::findOrFail($id);
            
            // Check if the tier is currently in use by any milestone awards
            $inUse = \DB::table('affiliate_milestone_awards')
                ->where('tier_id', $id)
                ->exists();
                
            if ($inUse) {
                return response()->json([
                    'success' => false,
                    'message' => 'This tier cannot be deleted as it is currently in use'
                ], 422);
            }
            
            $tier->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Sales milestone tier deleted successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error deleting sales milestone tier: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting sales milestone tier',
                'error' => $e->getMessage()
            ], $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException ? 404 : 500);
        }
    }
    
    /**
     * Get a list of all affiliate rewards.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getAllAffiliateRewards(): JsonResponse
    {
        try {
            $rewards = \DB::table('affiliate_rewards')
                ->join('users', 'affiliate_rewards.user_id', '=', 'users.id')
                ->select(
                    'affiliate_rewards.*',
                    'users.name as user_name',
                    'users.email as user_email'
                )
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $rewards
            ]);
        } catch (\Exception $e) {
            Log::error('Error getting affiliate rewards: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving affiliate rewards',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update an affiliate reward status.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateRewardStatus(Request $request, int $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|string|in:pending,awarded,fulfilled,cancelled',
                'notes' => 'nullable|string'
            ]);
            
            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $reward = \DB::table('affiliate_rewards')->where('id', $id)->first();
            
            if (!$reward) {
                return response()->json([
                    'success' => false,
                    'message' => 'Reward not found'
                ], 404);
            }
            
            $updateData = [
                'status' => $request->status,
                'updated_at' => now()
            ];
            
            if ($request->has('notes')) {
                $updateData['notes'] = $request->notes;
            }
            
            if ($request->status === 'fulfilled' && $reward->status !== 'fulfilled') {
                $updateData['fulfilled_at'] = now();
            }
            
            \DB::table('affiliate_rewards')
                ->where('id', $id)
                ->update($updateData);
            
            return response()->json([
                'success' => true,
                'message' => 'Reward status updated successfully'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error updating reward status: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating reward status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

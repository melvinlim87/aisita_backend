<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PromoCode;
use App\Models\Subscription;
use App\Services\PromoCodeService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PromoCodeController extends Controller
{
    protected $promoCodeService;
    
    public function __construct(PromoCodeService $promoCodeService)
    {
        $this->promoCodeService = $promoCodeService;
    }
    
    /**
     * Validate a promo code
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function validatePromoCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50',
            'plan_id' => 'nullable|exists:plans,id'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $user = Auth::user();
        $code = $request->input('code');
        $planId = $request->input('plan_id');
        
        $result = $this->promoCodeService->validatePromoCode($code, $user->id, $planId);
        
        if ($result['valid']) {
            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'discount_type' => $result['discount_type'],
                'discount_value' => $result['discount_value']
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => $result['message']
            ], 400);
        }
    }
    
    /**
     * Apply a promo code to a subscription
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function applyPromoCode(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50',
            'subscription_id' => 'required|exists:subscriptions,id'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        $user = Auth::user();
        $code = $request->input('code');
        $subscriptionId = $request->input('subscription_id');
        
        // Get the subscription
        $subscription = Subscription::find($subscriptionId);
        
        // Check ownership
        if ($subscription->user_id != $user->id && $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to apply promo code to this subscription'
            ], 403);
        }
        
        // Validate the promo code first
        $validationResult = $this->promoCodeService->validatePromoCode($code, $user->id, $subscription->plan_id);
        
        if (!$validationResult['valid']) {
            return response()->json([
                'success' => false,
                'message' => $validationResult['message']
            ], 400);
        }
        
        // Apply the promo code
        $result = $this->promoCodeService->applyPromoCode($validationResult['promo_code'], $user, $subscription);
        
        return response()->json($result, $result['success'] ? 200 : 400);
    }
    
    /**
     * List all promo codes (admin only)
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        if (Auth::user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }
        
        $promoCodes = PromoCode::all();
        
        return response()->json([
            'success' => true,
            'data' => $promoCodes
        ]);
    }
    
    /**
     * Store a new promo code (admin only)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        if (Auth::user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50|unique:promo_codes,code',
            'description' => 'nullable|string|max:255',
            'type' => 'required|in:percentage,fixed,free_month',
            'value' => 'required|numeric|min:0',
            'max_uses' => 'nullable|integer|min:0',
            'max_uses_per_user' => 'nullable|integer|min:1',
            'plan_id' => 'nullable|exists:plans,id',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $promoCode = PromoCode::create($request->all());
            
            return response()->json([
                'success' => true,
                'message' => 'Promo code created successfully',
                'data' => $promoCode
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating promo code: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create promo code'
            ], 500);
        }
    }
    
    /**
     * Update a promo code (admin only)
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id)
    {
        if (Auth::user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }
        
        $promoCode = PromoCode::find($id);
        
        if (!$promoCode) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code not found'
            ], 404);
        }
        
        $validator = Validator::make($request->all(), [
            'code' => 'string|max:50|unique:promo_codes,code,' . $id,
            'description' => 'nullable|string|max:255',
            'type' => 'in:percentage,fixed,free_month',
            'value' => 'numeric|min:0',
            'max_uses' => 'nullable|integer|min:0',
            'max_uses_per_user' => 'nullable|integer|min:1',
            'plan_id' => 'nullable|exists:plans,id',
            'is_active' => 'boolean',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after:starts_at'
        ]);
        
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }
        
        try {
            $promoCode->update($request->all());
            
            return response()->json([
                'success' => true,
                'message' => 'Promo code updated successfully',
                'data' => $promoCode
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating promo code: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update promo code'
            ], 500);
        }
    }
    
    /**
     * Delete a promo code (admin only)
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        if (Auth::user()->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access'
            ], 403);
        }
        
        $promoCode = PromoCode::find($id);
        
        if (!$promoCode) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code not found'
            ], 404);
        }
        
        try {
            $promoCode->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Promo code deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting promo code: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete promo code'
            ], 500);
        }
    }
}

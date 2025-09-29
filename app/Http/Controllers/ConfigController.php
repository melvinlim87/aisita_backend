<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ConfigController extends Controller
{
    /**
     * Get the reCAPTCHA site key from the environment
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getReCaptchaSiteKey()
    {
        $siteKey = env('RECAPTCHA_SITE_KEY', '');
        
        if (empty($siteKey)) {
            return response()->json([
                'success' => false,
                'message' => 'reCAPTCHA site key is not configured',
                'siteKey' => null
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'siteKey' => $siteKey
        ]);
    }
    
    /**
     * Verify a reCAPTCHA token
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyReCaptcha(Request $request)
    {
        $token = $request->input('token');
        $secretKey = env('RECAPTCHA_SECRET_KEY', '');
        
        if (empty($secretKey)) {
            return response()->json([
                'success' => false,
                'message' => 'reCAPTCHA secret key is not configured'
            ], 500);
        }
        
        if (empty($token)) {
            return response()->json([
                'success' => false,
                'message' => 'reCAPTCHA token is required'
            ], 400);
        }
        
        try {
            $verifyResponse = file_get_contents(
                'https://www.google.com/recaptcha/api/siteverify?secret='.urlencode($secretKey).
                '&response='.urlencode($token)
            );
            
            $responseData = json_decode($verifyResponse);
            
            if ($responseData->success) {
                return response()->json([
                    'success' => true,
                    'message' => 'reCAPTCHA verification successful'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'reCAPTCHA verification failed',
                    'errors' => $responseData->{'error-codes'} ?? []
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error verifying reCAPTCHA: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get the Telegram bot ID from the environment
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTelegramConfig()
    {
        $botId = env('TELEGRAM_BOT_ID', '');
        
        if (empty($botId)) {
            return response()->json([
                'success' => false,
                'message' => 'Telegram bot ID is not configured',
                'bot_id' => null
            ], 500);
        }
        
        return response()->json([
            'success' => true,
            'bot_id' => $botId
        ]);
    }

    public function getTelegramUsername()
{
    return response()->json([
        'bot_id' => env('TELEGRAM_BOT_USERNAME', 'DecyphersAIBot')
    ]);
}
}

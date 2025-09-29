<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;
use App\Models\Verification;
use Illuminate\Support\Facades\Auth;

class TelegramWebhookController extends Controller
{
    public function __construct()
    {
        // Bot commands should be set up once via artisan command or separate endpoint
        // Removing from constructor to improve webhook performance
    }

    private function setUpBotCommands()
    {
        try {
            Telegram::setMyCommands([
                [
                    'command' => 'start',
                    'description' => 'Start the verification process'
                ],
                [
                    'command' => 'reset',
                    'description' => 'Reset your password'
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to set up bot commands: ' . $e->getMessage());
        }
    }

    private function getStartKeyboard()
    {
        return [
            'keyboard' => [
                [['text' => '/start'], ['text' => '/reset']]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
    }

    public function handle(Request $request)
    {
        $update = $request->all();
        
        // Log the update for debugging
        \Log::info('Telegram Webhook Update:', $update);

        // Handle new chat member (when user first starts the bot)
        if (isset($update['my_chat_member'])) {
            $chatId = $update['my_chat_member']['chat']['id'];
            
            // Send welcome message with keyboard
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "Welcome to Decyphers!\n\nUse /start to link your account.",
                'reply_markup' => json_encode($this->getStartKeyboard())
            ]);
            
            return response()->json(['status' => 'success']);
        }

        if (!isset($update['message']['text'])) {
            return response()->json(['status' => 'success']);
        }

        $message = $update['message'];
        $chatId = $message['chat']['id'];
        $text = $message['text'];

        if ($text === '/start') {
            try {
                // Generate a 6-digit verification code
                $verificationCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                
                // Get username safely
                $username = isset($message['chat']['username']) ? $message['chat']['username'] : null;
                
                // Create verification record with the fields that match our schema
                Verification::create([
                    'uid' => $chatId,
                    'email' => null,
                    'username' => $username,
                    'verification_code' => $verificationCode,
                    'app' => 'telegram',
                    'type' => 'telegram_connect',
                    'expires_at' => now()->addMinutes(15)
                ]);
                
                // Send verification code with keyboard (async to improve response time)
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "🔐 Your verification code is: $verificationCode\n\nThis code will expire in 15 minutes.\n\nEnter this code in the Decyphers app to connect your Telegram account.",
                    'reply_markup' => json_encode($this->getStartKeyboard())
                ]);
                
                \Log::info('Verification code sent successfully', ['chat_id' => $chatId, 'code' => $verificationCode]);
                
            } catch (\Exception $e) {
                \Log::error('Error processing /start command: ' . $e->getMessage(), ['chat_id' => $chatId]);
                
                // Send error message to user
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Sorry, there was an error processing your request. Please try again later."
                ]);
            }
        }

        return response()->json(['status' => 'success']);
    }
    
    /**
     * Verify the Telegram verification code and connect the account to the user
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyCode(Request $request)
    {
        $request->validate([
            'verification_code' => 'required|string|size:6',
            'profile_complete' => 'boolean'
        ]);
        
        // Find the verification record with the provided code
        $verification = Verification::where('verification_code', $request->verification_code)
            ->where('app', 'telegram')
            ->where('type', 'telegram_connect')
            ->where('verified_at', null)
            ->where('expires_at', '>', now())
            ->first();
        
        if (!$verification) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired verification code'
            ], 400);
        }
        
        // Get the authenticated user
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not authenticated'
            ], 401);
        }
        
        // Check if profile is complete
        $requiredFields = ['name', 'phone_number', 'street_address', 'city', 'state', 'zip_code', 'country', 'date_of_birth', 'gender'];
        $isProfileComplete = true;
        
        foreach ($requiredFields as $field) {
            if (empty($user->$field)) {
                $isProfileComplete = false;
                break;
            }
        }
        
        // Update the user's Telegram ID
        $user->telegram_id = $verification->uid;
        
        // If the Telegram username is available in the verification record, update it too
        if ($verification->username) {
            $user->telegram_username = $verification->username;
        }

        $tokensAwarded = 0;
        $message = 'Telegram account connected successfully';
        
        // Only award tokens if profile is complete
        if ($isProfileComplete) {
            $user->free_token += 4000;
            $tokensAwarded = 4000;
            $message = 'Telegram account connected successfully! You have been awarded 4000 free tokens.';
        } else {
            $message = 'Telegram account connected successfully! Complete your profile to receive 4000 free tokens.';
        }
        
        $user->save();
        
        // Mark the verification as verified
        $verification->verified_at = now();
        $verification->email = $user->email; // Link the verification to the user's email

        $verification->save();
        
        return response()->json([
            'success' => true,
            'message' => $message,
            'user' => $user,
            'tokens_awarded' => $tokensAwarded,
            'profile_complete' => $isProfileComplete
        ]);
    }

    public function checkTelegramConnect(Request $request)
    {
        $userId = Auth::id();

        $user = \App\Models\User::where('id', $userId)
            ->where('telegram_id', '!=', null)
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Telegram not connected'
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => 'Telegram connected',
            'user' => $user
        ]);
    }
}

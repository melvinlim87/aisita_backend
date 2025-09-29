<?php

namespace App\Telegram\Commands;

use App\Models\Verification;
use Telegram\Bot\Commands\Command;
use Illuminate\Support\Str;

class StartCommand extends Command
{
    /**
     * @var string
     */
    protected string $name = 'start';

    /**
     * @var string
     */
    protected string $description = 'Start Command to get verification code';

    public function handle()
    {
        $update = $this->getUpdate();
        $message = $update->getMessage();
        $chat = $message->getChat();
        
        // Generate a random 6-digit verification code
        $verificationCode = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        // Store the verification details
        // Generate a unique ID for this verification
        $uid = uniqid('tg_', true);
        
        // Get the Telegram chat ID to store as the unique identifier
        $chatId = $chat->getId();
        
        // Use the Verification model to store the verification code
        \App\Models\Verification::create([
            'uid' => $chatId, // Store the Telegram chat ID as the unique identifier
            'email' => null, // Email will be linked later when user enters code on website
            'verification_code' => $verificationCode,
            'app' => 'telegram',
            'type' => 'telegram_connect',
            'expires_at' => now()->addMinutes(15) // Code expires in 15 minutes
        ]);
        
        // You might want to store the chat ID and other Telegram details in a separate table
        // or modify your verifications table to include these fields

        // Send the verification code to the user
        $this->replyWithMessage([
            'text' => "Welcome to Decyphers!\n\nYour verification code is: $verificationCode\n\nPlease enter this code in the Decyphers app to connect your Telegram account. This code will expire in 15 minutes.",
        ]);
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Database;

class FirebaseService
{
    protected $database;

    public function __construct()
    {
        try {
            $serviceAccount = base_path(env('FIREBASE_CREDENTIALS'));
            $databaseUrl = env('FIREBASE_DATABASE_URL');

            $firebase = (new Factory)
                ->withServiceAccount($serviceAccount)
                ->withDatabaseUri($databaseUrl)
                ->createDatabase();

            $this->database = $firebase;
        } catch (\Exception $e) {
            Log::error('Firebase initialization error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get user data from Firebase
     */
    public function getUserData(string $userId)
    {
        try {
            $reference = $this->database->getReference('users/' . $userId);
            $snapshot = $reference->getSnapshot();
            
            if (!$snapshot->exists()) {
                return null;
            }
            
            return $snapshot->getValue();
        } catch (\Exception $e) {
            Log::error('Error getting user data from Firebase: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update user tokens in Firebase
     */
    public function updateUserTokens(string $userId, int $tokensToAdd, array $purchaseData = [])
    {
        try {
            // Get current user data
            $userData = $this->getUserData($userId);
            
            if (!$userData) {
                throw new \Exception("User not found in Firebase: {$userId}");
            }
            
            // Calculate new token amount
            $currentTokens = $userData['tokens'] ?? 0;
            $newTokens = $currentTokens + $tokensToAdd;
            
            // Prepare updates
            $updates = [
                'tokens' => $newTokens,
                'updatedAt' => date('c'),
                'lastPurchase' => [
                    'amount' => $tokensToAdd,
                    'timestamp' => date('c'),
                    'sessionId' => $purchaseData['sessionId'] ?? null,
                    'priceId' => $purchaseData['priceId'] ?? null,
                ]
            ];
            
            // Add to purchase history if session ID is provided
            if (isset($purchaseData['sessionId'])) {
                // Check if this session ID already exists in the user's purchases
                $existingPurchase = null;
                if (isset($userData['purchases']) && isset($userData['purchases'][$purchaseData['sessionId']])) {
                    $existingPurchase = $userData['purchases'][$purchaseData['sessionId']];
                    Log::info('Found existing purchase with session ID: ' . $purchaseData['sessionId']);
                }
                
                // Only add the purchase if it doesn't exist or if we're updating its status
                if (!$existingPurchase || 
                    ($existingPurchase['status'] === 'pending' && $purchaseData['status'] === 'paid')) {
                    
                    $updates['purchases/' . $purchaseData['sessionId']] = [
                        'tokens' => $tokensToAdd,
                        'amount' => $purchaseData['amount'] ?? 0,
                        'date' => date('c'),
                        'status' => $purchaseData['status'] ?? 'completed',
                        'priceId' => $purchaseData['priceId'] ?? null,
                        'customerEmail' => $purchaseData['customerEmail'] ?? null,
                        'currency' => $purchaseData['currency'] ?? 'usd',
                        'type' => $purchaseData['type'] ?? 'purchase' // Default to 'purchase' if not specified
                    ];
                }
            }
            
            // Update Firebase
            $reference = $this->database->getReference('users/' . $userId);
            $reference->update($updates);
            
            // Verify the update
            $updatedData = $this->getUserData($userId);
            
            if ($updatedData['tokens'] !== $newTokens) {
                throw new \Exception("Token update verification failed");
            }
            
            return [
                'success' => true,
                'previousTokens' => $currentTokens,
                'tokensAdded' => $tokensToAdd,
                'newTotal' => $newTokens
            ];
        } catch (\Exception $e) {
            Log::error('Error updating user tokens in Firebase: ' . $e->getMessage());
            throw $e;
        }
    }
}

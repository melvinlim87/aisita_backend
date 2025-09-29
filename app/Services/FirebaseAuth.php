<?php

namespace App\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Auth;
use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Exception\AuthException;

class FirebaseAuth
{
    protected $auth;

    public function __construct()
    {
        $serviceAccountPath = storage_path('app/firebase/firebase_credentials.json');
        
        // Create factory with explicit project ID from env or hardcoded
        $projectId = env('FIREBASE_PROJECT_ID', 'ai-crm-windsurf');
        
        $factory = (new Factory)
            ->withServiceAccount($serviceAccountPath)
            ->withProjectId($projectId);
            
        $this->auth = $factory->createAuth();
    }

    public function verifyIdToken($idToken)
    {
        try {
            return $this->auth->verifyIdToken($idToken);
        } catch (FirebaseException | AuthException | \InvalidArgumentException $e) {
            throw new \RuntimeException('Token verification failed: ' . $e->getMessage(), 0, $e);
        }
    }
}

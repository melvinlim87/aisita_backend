<?php

use Illuminate\Support\Facades\Route;
use Kreait\Firebase\Factory;

Route::get('/debug/firebase', function() {
    try {
        // Check if credentials file exists
        $serviceAccountPath = storage_path('app/firebase/firebase_credentials.json');
        if (!file_exists($serviceAccountPath)) {
            return response()->json([
                'error' => 'Credentials file not found',
                'path' => $serviceAccountPath
            ], 500);
        }

        // Try to initialize Firebase
        $factory = (new Factory)
            ->withServiceAccount($serviceAccountPath)
            ->withDatabaseUri(env('FIREBASE_DATABASE_URL'));

        $auth = $factory->createAuth();
        
        // If we get here, Firebase was initialized successfully
        return response()->json([
            'success' => true,
            'auth_initialized' => $auth !== null,
            'database_url' => env('FIREBASE_DATABASE_URL')
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

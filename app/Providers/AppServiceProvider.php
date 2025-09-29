<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\FirebaseAuth;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Contract\Auth as FirebaseAuthContract;
use Kreait\Firebase\Contract\Storage as FirebaseStorageContract;
use Kreait\Firebase\Contract\Firestore as FirestoreContract;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Firebase Factory
        $this->app->singleton(Factory::class, function ($app) {
            $serviceAccountPath = storage_path('app/firebase/firebase_credentials.json');
            
            if (!file_exists($serviceAccountPath)) {
                throw new \RuntimeException('Firebase credentials file not found at: ' . $serviceAccountPath);
            }
            
            try {
                $factory = new Factory();
                $factory = $factory->withServiceAccount($serviceAccountPath);
                
                if (env('FIREBASE_DATABASE_URL')) {
                    $factory = $factory->withDatabaseUri(env('FIREBASE_DATABASE_URL'));
                }
                
                return $factory;
            } catch (\Exception $e) {
                throw new \RuntimeException('Failed to initialize Firebase: ' . $e->getMessage(), 0, $e);
            }
        });

        // Register Firebase Auth
        $this->app->singleton('firebase.auth', function ($app) {
            return $app->make(Factory::class)->createAuth();
        });

        // Register Firebase Auth as a contract
        $this->app->bind(FirebaseAuthContract::class, function ($app) {
            return $app->make('firebase.auth');
        });

        // Register Firestore
        $this->app->singleton('firebase.firestore', function ($app) {
            return $app->make(Factory::class)->createFirestore();
        });

        // Register Firestore as a contract
        $this->app->bind(FirestoreContract::class, function ($app) {
            return $app->make('firebase.firestore');
        });

        // Register Firebase Storage
        $this->app->singleton('firebase.storage', function ($app) {
            return $app->make(Factory::class)->createStorage();
        });

        // Register Firebase Storage as a contract
        $this->app->bind(FirebaseStorageContract::class, function ($app) {
            return $app->make('firebase.storage');
        });

        // Keep the existing FirebaseAuth service binding
        $this->app->singleton(\App\Services\FirebaseAuth::class, function ($app) {
            return new \App\Services\FirebaseAuth();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

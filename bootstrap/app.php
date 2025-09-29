<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        api: __DIR__.'/../routes/api.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // We're not using Laravel's built-in CORS middleware
        // Instead, we're using our custom CORS middleware
        
        // Explicitly remove Laravel's HandleCors from the global middleware stack
        $middleware->remove(\Illuminate\Http\Middleware\HandleCors::class);
        
        // Register our custom CORS middleware globally
        $middleware->use([
            \App\Http\Middleware\CorsMiddleware::class,
        ]);
        
        $middleware->web(append: [
            // Your web middleware here
        ]);
        
        $middleware->api(append: [
            // Your API middleware here
            \App\Http\Middleware\LogApiRequestsMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withProviders()
    ->create();

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\DemoFundingController;
use App\Http\Middleware\EnsureDemoFundingEnabled;

Route::prefix('v1')->group(function () {

    Route::post('/register', [
        AuthController::class,
        'register'
    ])->middleware('throttle:5,1');

    Route::post('/login', [
        AuthController::class,
        'login'
    ])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/me', [
            AuthController::class,
            'me'
        ]);

        Route::post('/logout', [
            AuthController::class,
            'logout'
        ]);

        Route::get('/wallet', [
            WalletController::class,
            'show'
        ]);

        Route::get('/wallet/transactions', [
            WalletController::class,
            'transactions'
        ]);

        Route::get('/wallet/statement', [
            WalletController::class,
            'statement'
        ]);

        // Demo money only. Returns 404 unless DEMO_FUNDING_ENABLED=true
        // in .env, and is always 404 when APP_ENV=production.
        Route::post('/wallet/fund-demo', [
            DemoFundingController::class,
            'fund'
        ])->middleware([
            EnsureDemoFundingEnabled::class,
            'throttle:5,1',
        ]);
    });
});
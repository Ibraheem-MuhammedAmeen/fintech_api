<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\DemoFundingController;

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
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/wallet', [WalletController::class, 'show']);

    Route::get(
        '/wallet/transactions',
        [WalletController::class, 'transactions']
    );

    Route::get(
        '/wallet/statement',
        [WalletController::class, 'statement']
    );

    Route::post(
        '/wallet/fund-demo',
        [DemoFundingController::class, 'fund']
    )->middleware('throttle:5,1');
});
});
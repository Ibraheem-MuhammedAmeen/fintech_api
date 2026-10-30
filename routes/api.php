<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AccountLookupController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\DemoFundingController;
use App\Http\Controllers\Api\TransferController;
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

        // Dummy money (cannot leave the app). Returns 404 unless
        // DEMO_FUNDING_ENABLED=true in .env.
        Route::post('/wallet/fund-demo', [
            DemoFundingController::class,
            'fund'
        ])->middleware([
            EnsureDemoFundingEnabled::class,
            'throttle:5,1',
        ]);

        // Wallet-to-wallet transfers.
        Route::get('/transfers', [
            TransferController::class,
            'index'
        ]);

        Route::post('/transfers', [
            TransferController::class,
            'store'
        ])->middleware('throttle:10,1');

        // whereNumber: /transfers/abc returns 404 instead of an error.
        Route::get('/transfers/{transfer}', [
            TransferController::class,
            'show'
        ])->whereNumber('transfer');

        // "Who owns this account number?" Shows the name before sending.
        // throttle:30,1 so nobody can scan through every phone number.
        Route::get('/accounts/{account_number}', [
            AccountLookupController::class,
            'show'
        ])->middleware('throttle:30,1');
    });
});
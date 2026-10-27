<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WalletStatementRequest;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WalletTransactionResource;
use App\Services\WalletService;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(
        protected WalletService $walletService
    ) {}

    public function show(Request $request): WalletResource
    {
        $wallet = $this->walletService
            ->getWallet($request->user());

        $wallet->balance_kobo = $this->walletService
            ->getBalance($wallet);

        return new WalletResource($wallet);
    }

    public function transactions(Request $request)
    {
        $wallet = $this->walletService
            ->getWallet($request->user());

        $perPage = min(
            max((int) $request->query('per_page', 15), 1),
            100
        );

        $transactions = $this->walletService
            ->getTransactions($wallet, $perPage);

        return WalletTransactionResource::collection(
            $transactions
        );
    }

    public function statement(WalletStatementRequest $request)
    {
        $wallet = $this->walletService
            ->getWallet($request->user());

        $validated = $request->validated();

        $perPage = min(
            max((int) ($validated['per_page'] ?? 15), 1),
            100
        );

        $transactions = $this->walletService
            ->getStatement(
                $wallet,
                $validated['from'],
                $validated['to'],
                $perPage
            );

        return WalletTransactionResource::collection(
            $transactions
        );
    }
}
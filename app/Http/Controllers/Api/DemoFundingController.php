<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\WalletFundingRequest;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DemoFundingController extends Controller
{
    public function __construct(
        protected WalletService $walletService
    ) {
    }

    public function fund(Request $request)
    {
        $validated = $request->validate([
            'amount_kobo' => [
                'required',
                'integer',
                'min:100',
                'max:100000000',
            ],
        ]);

        $amountKobo = (int) $validated['amount_kobo'];

        $wallet = $this->walletService
            ->getWallet($request->user());

        if ($wallet->status !== 'active') {
            throw ValidationException::withMessages([
                'wallet' => 'Your wallet is not active.',
            ]);
        }

        $result = DB::transaction(function () use ($wallet, $amountKobo) {
            // Lock wallet to serialize funding operations.
            $wallet = $wallet->newQuery()
                ->whereKey($wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($wallet->status !== 'active') {
                throw ValidationException::withMessages([
                    'wallet' => 'Your wallet is not active.',
                ]);
            }

            $reference = 'DF-' . Str::uuid();

            // Demo source account: debit.
            $sourceAccount = LedgerAccount::firstOrCreate(
                [
                    'account_code' => 'DEMO-FUNDING-NGN',
                ],
                [
                    'wallet_id' => null,
                    'account_type' => 'asset',
                    'currency' => 'NGN',
                ]
            );

            // Wallet liability account: credit.
            $walletAccount = LedgerAccount::firstOrCreate(
                [
                    'account_code' => 'WALLET-' . $wallet->id,
                ],
                [
                    'wallet_id' => $wallet->id,
                    'account_type' => 'liability',
                    'currency' => $wallet->currency,
                ]
            );

            if (
                $wallet->currency !== 'NGN' ||
                $sourceAccount->account_type !== 'asset' ||
                $sourceAccount->currency !== 'NGN' ||
                $sourceAccount->wallet_id !== null ||
                $walletAccount->account_type !== 'liability' ||
                $walletAccount->currency !== $wallet->currency ||
                (int) $walletAccount->wallet_id !== (int) $wallet->id
            ) {
                throw ValidationException::withMessages([
                    'wallet' => 'Ledger account configuration is invalid.',
                ]);
            }

            // Group the accounting entries.
            $ledgerTransaction = LedgerTransaction::create([
                'reference' => $reference,
                'type' => 'demo_funding',
                'status' => 'posted',
                'description' => 'Demo wallet funding',
            ]);

            // Debit the demo funding source.
            LedgerEntry::create([
                'ledger_transaction_id' => $ledgerTransaction->id,
                'ledger_account_id' => $sourceAccount->id,
                'direction' => 'debit',
                'amount_kobo' => $amountKobo,
                'created_at' => now(),
            ]);

            // Credit the user's wallet liability.
            LedgerEntry::create([
                'ledger_transaction_id' => $ledgerTransaction->id,
                'ledger_account_id' => $walletAccount->id,
                'direction' => 'credit',
                'amount_kobo' => $amountKobo,
                'created_at' => now(),
            ]);

            // Record the demo funding request.
            $fundingRequest = WalletFundingRequest::create([
                'wallet_id' => $wallet->id,
                'reference' => $reference,
                'amount_kobo' => $amountKobo,
                'ledger_transaction_id' => $ledgerTransaction->id,
            ]);

            // Keep your existing WalletService balance working.
            WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'reference' => $reference,
                'type' => 'credit',
                'amount_kobo' => $amountKobo,
                'description' => 'Demo wallet funding',
            ]);

            $balanceKobo = $this->walletService
                ->getBalance($wallet);

            return [
                'reference' => $reference,
                'funding_request_id' => $fundingRequest->id,
                'wallet_id' => $wallet->id,
                'amount_kobo' => $amountKobo,
                'balance_kobo' => $balanceKobo,
            ];
        }, 3);

        return response()->json([
            'message' => 'Demo funding successful.',
            'data' => $result,
        ], 201);
    }
}
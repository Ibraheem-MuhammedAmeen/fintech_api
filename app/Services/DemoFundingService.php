<?php

namespace App\Services;

use App\Exceptions\DemoFundingException;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletFundingRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Adds simulated ("demo") money to a user's wallet.
 *
 * Every successful call writes, inside ONE database transaction:
 *   1 ledger_transactions row     (the financial event)
 *   2 ledger_entries rows         (debit demo source, credit wallet; they balance)
 *   1 wallet_funding_requests row (who asked for how much, plus the idempotency key)
 *   1 wallet_transactions row     (the credit the wallet balance is calculated from)
 *
 * If anything fails half-way, the database undoes ALL of these writes.
 */
class DemoFundingService
{
    public function __construct(
        protected WalletService $walletService
    ) {}

    /**
     * @return array{funding: WalletFundingRequest, balance_kobo: int, replayed: bool}
     *   replayed = true means "this Idempotency-Key was already processed,
     *   so nothing new was written; here is the original result".
     */
    public function fund(User $user, int $amountKobo, string $idempotencyKey): array
    {
        try {
            // The 3 means: if MySQL reports a deadlock, retry up to 3 times.
            return DB::transaction(
                fn () => $this->fundInsideTransaction($user, $amountKobo, $idempotencyKey),
                3
            );
        } catch (UniqueConstraintViolationException $e) {
            // Safety net: two identical requests raced each other and the
            // database's unique index stopped the second one. Everything the
            // second one wrote has been rolled back, so just return the
            // result of the first one.
            $existing = $this->findExisting($user, $idempotencyKey);

            if ($existing === null) {
                throw $e; // a different unique error; don't hide it
            }

            return $this->replay($existing, $amountKobo);
        }
    }

    private function fundInsideTransaction(User $user, int $amountKobo, string $idempotencyKey): array
    {
        // 1. Load the user's wallet and LOCK its row until we commit.
        //    A second request for the same wallet now waits here until
        //    this one finishes, so two requests can never both pass the
        //    idempotency check below at the same time.
        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->firstOrFail();

        // 2. Idempotency: was this exact request already processed?
        $existing = WalletFundingRequest::query()
            ->where('wallet_id', $wallet->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $this->replay($existing, $amountKobo);
        }

        // 3. Business rules.
        if ($wallet->status !== 'active') {
            throw DemoFundingException::walletNotActive($wallet->status);
        }

        if ($wallet->currency !== 'NGN') {
            throw DemoFundingException::unsupportedCurrency($wallet->currency);
        }

        // 4. References.
        //    wallet_transactions.reference is a UUID column (exactly 36 chars),
        //    so it gets the bare UUID. The ledger and funding request get the
        //    same UUID with a "DF-" prefix, so all rows can be matched up.
        $uuid = (string) Str::uuid();
        $reference = 'DF-' . $uuid;
        $now = now();

        // 5. Ledger accounts (created the first time, reused afterwards).
        $demoSource = LedgerAccount::firstOrCreate(
            ['account_code' => LedgerAccount::DEMO_FUNDING_NGN],
            ['wallet_id' => null, 'account_type' => 'asset', 'currency' => 'NGN']
        );

        $walletAccount = LedgerAccount::firstOrCreate(
            ['account_code' => LedgerAccount::walletCode($wallet->id)],
            ['wallet_id' => $wallet->id, 'account_type' => 'liability', 'currency' => $wallet->currency]
        );

        // 6. The ledger transaction and its two balanced entries.
        $ledgerTransaction = LedgerTransaction::create([
            'reference' => $reference,
            'type' => 'demo_funding',
            'status' => 'posted',
            'description' => "Demo funding for wallet {$wallet->wallet_number}",
        ]);

        $ledgerTransaction->entries()->createMany([
            [
                'ledger_account_id' => $demoSource->id,
                'direction' => 'debit',
                'amount_kobo' => $amountKobo,
                'created_at' => $now,
            ],
            [
                'ledger_account_id' => $walletAccount->id,
                'direction' => 'credit',
                'amount_kobo' => $amountKobo,
                'created_at' => $now,
            ],
        ]);

        $this->assertBalanced($ledgerTransaction);

        // 7. The funding request record.
        $funding = WalletFundingRequest::create([
            'wallet_id' => $wallet->id,
            'reference' => $reference,
            'idempotency_key' => $idempotencyKey,
            'amount_kobo' => $amountKobo,
            'ledger_transaction_id' => $ledgerTransaction->id,
        ]);

        // 8. The wallet credit. This is what WalletService::getBalance() adds up.
        $wallet->transactions()->create([
            'reference' => $uuid,
            'type' => 'credit',
            'amount_kobo' => $amountKobo,
            'description' => "Demo funding ({$reference})",
        ]);

        return [
            'funding' => $funding,
            'balance_kobo' => $this->walletService->getBalance($wallet),
            'replayed' => false,
        ];
    }

    /**
     * Return the result of an already-processed request,
     * without writing anything new.
     */
    private function replay(WalletFundingRequest $existing, int $amountKobo): array
    {
        // Same key but a different amount is almost certainly a client bug.
        // Refuse instead of guessing which amount was meant.
        if ($existing->amount_kobo !== $amountKobo) {
            throw DemoFundingException::idempotencyKeyReused();
        }

        return [
            'funding' => $existing,
            'balance_kobo' => $this->walletService->getBalance($existing->wallet),
            'replayed' => true,
        ];
    }

    private function findExisting(User $user, string $idempotencyKey): ?WalletFundingRequest
    {
        return WalletFundingRequest::query()
            ->whereHas('wallet', fn ($q) => $q->where('user_id', $user->id))
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Double-entry rule: total debits must equal total credits.
     * We re-read the entries from the database and check. If this ever
     * fails, the exception rolls back the whole transaction.
     */
    private function assertBalanced(LedgerTransaction $ledgerTransaction): void
    {
        $totals = $ledgerTransaction->entries()
            ->selectRaw('direction, SUM(amount_kobo) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        $debits = (int) ($totals['debit'] ?? 0);
        $credits = (int) ($totals['credit'] ?? 0);

        if ($debits === 0 || $debits !== $credits) {
            throw new LogicException(
                "Ledger transaction {$ledgerTransaction->reference} is unbalanced: "
                . "debits={$debits}, credits={$credits}."
            );
        }
    }
}
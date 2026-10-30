<?php

namespace App\Services;

use App\Exceptions\TransferException;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Wallet-to-wallet transfers (dummy money, inside the app only).
 *
 * Every successful transfer writes, inside ONE database transaction:
 *   1 ledger_transactions row  (type "transfer")
 *   2 ledger_entries rows      (debit sender's WALLET account, credit recipient's)
 *   1 transfers row
 *   2 wallet_transactions rows (debit for the sender, credit for the recipient)
 *
 * If anything fails half-way, ALL of these writes are undone.
 */
class TransferService
{
    public function __construct(
        protected WalletService $walletService,
        protected LedgerService $ledgerService,
    ) {}

    // =================================================================
    // POST /transfers
    // =================================================================

    /**
     * @return array{transfer: Transfer, balance_kobo: int, replayed: bool}
     *   balance_kobo = the SENDER's balance after the transfer.
     *   replayed = true means this Idempotency-Key was already processed,
     *   nothing new was written, and this is the original transfer.
     */
    public function send(
        User $sender,
        string $recipientWalletNumber,
        int $amountKobo,
        ?string $narration,
        string $idempotencyKey,
    ): array {
        // 1. Find the two wallet IDs BEFORE the database transaction starts.
        //
        //    IMPORTANT (this fixed a real double-spend bug): in MySQL, the
        //    first normal SELECT inside a transaction takes a "snapshot" of
        //    the data, and later normal SELECTs keep seeing that snapshot.
        //    If we looked up the IDs inside the transaction, the snapshot
        //    would be taken BEFORE we wait for the wallet lock, and after
        //    the wait we would still see the old balance, so 5 parallel
        //    transfers could all pass the balance check.
        $senderWalletId = Wallet::where('user_id', $sender->id)->value('id')
            ?? abort(404, 'You do not have a wallet.');

        // Validation already checked the number exists; this is just a lookup.
        $recipientWalletId = Wallet::where('wallet_number', $recipientWalletNumber)->value('id')
            ?? abort(422, 'No account was found with this account number.');

        try {
            // 3 = retry up to 3 times if MySQL reports a deadlock.
            return DB::transaction(
                fn () => $this->sendInsideTransaction($senderWalletId, $recipientWalletId, $amountKobo, $narration, $idempotencyKey),
                3
            );
        } catch (UniqueConstraintViolationException $e) {
            // Safety net: two identical requests raced and the unique index
            // (sender_wallet_id, idempotency_key) stopped the second one.
            // Its writes were rolled back; return the first one's result.
            $existing = Transfer::query()
                ->whereHas('senderWallet', fn ($q) => $q->where('user_id', $sender->id))
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $e;
            }

            return $this->replay($existing, $amountKobo, $existing->recipient_wallet_id);
        }
    }

    private function sendInsideTransaction(
        int $senderWalletId,
        int $recipientWalletId,
        int $amountKobo,
        ?string $narration,
        string $idempotencyKey,
    ): array {
        // 2. FIRST statement in the transaction: lock BOTH wallet rows
        //    until we commit, always in ID order.
        //    Why ID order? If A sends to B while B sends to A, and each
        //    request locked "its own" wallet first, they would wait for each
        //    other forever (a deadlock). Locking in the same order avoids it.
        //    While locked, no other transfer/funding can change these balances.
        $locked = Wallet::query()
            ->whereIn('id', [$senderWalletId, $recipientWalletId])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $senderWallet = $locked[$senderWalletId];
        $recipientWallet = $locked[$recipientWalletId];

        // 3. Idempotency: was this exact request already processed?
        $existing = Transfer::query()
            ->where('sender_wallet_id', $senderWallet->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $this->replay($existing, $amountKobo, $recipientWallet->id);
        }

        // 4. Business rules.
        if ($senderWallet->id === $recipientWallet->id) {
            throw TransferException::selfTransfer();
        }

        if ($senderWallet->status !== 'active') {
            throw TransferException::senderWalletNotActive($senderWallet->status);
        }

        if ($recipientWallet->status !== 'active') {
            throw TransferException::recipientWalletNotActive();
        }

        if ($senderWallet->currency !== $recipientWallet->currency) {
            throw TransferException::currencyMismatch();
        }

        // 5. Enough money? Checked AFTER locking, so the balance cannot
        //    change between this check and the debit below. getLockedBalance()
        //    uses locking reads, which always see the latest committed rows
        //    (never an old snapshot).
        if ($this->walletService->getLockedBalance($senderWallet) < $amountKobo) {
            throw TransferException::insufficientFunds();
        }

        // 6. References. wallet_transactions.reference must be unique and is
        //    a UUID column (36 chars), so each wallet row gets its own UUID.
        //    The transfer and ledger share "TR-" + another UUID.
        $reference = 'TR-' . Str::uuid();

        // 7. Ledger: debit the sender's wallet account (the platform now owes
        //    the sender less), credit the recipient's (owes the recipient more).
        $ledgerTransaction = $this->ledgerService->post(
            'transfer',
            $reference,
            "Transfer {$senderWallet->wallet_number} -> {$recipientWallet->wallet_number}",
            [
                ['account' => $this->ledgerService->walletAccount($senderWallet), 'direction' => 'debit', 'amount_kobo' => $amountKobo],
                ['account' => $this->ledgerService->walletAccount($recipientWallet), 'direction' => 'credit', 'amount_kobo' => $amountKobo],
            ]
        );

        // 8. The transfer record.
        $transfer = Transfer::create([
            'reference' => $reference,
            'sender_wallet_id' => $senderWallet->id,
            'recipient_wallet_id' => $recipientWallet->id,
            'amount_kobo' => $amountKobo,
            'currency' => $senderWallet->currency,
            'narration' => $narration,
            'status' => 'successful',
            'ledger_transaction_id' => $ledgerTransaction->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        // 9. Wallet history rows. These are what getBalance() adds up,
        //    so the sender's balance goes down and the recipient's goes up.
        $senderWallet->transactions()->create([
            'reference' => (string) Str::uuid(),
            'type' => 'debit',
            'amount_kobo' => $amountKobo,
            'description' => "Transfer to {$recipientWallet->wallet_number} ({$reference})",
        ]);

        $recipientWallet->transactions()->create([
            'reference' => (string) Str::uuid(),
            'type' => 'credit',
            'amount_kobo' => $amountKobo,
            'description' => "Transfer from {$senderWallet->wallet_number} ({$reference})",
        ]);

        return [
            'transfer' => $transfer,
            'balance_kobo' => $this->walletService->getBalance($senderWallet),
            'replayed' => false,
        ];
    }

    /**
     * Return an already-processed transfer without writing anything.
     * The same key must mean the same transfer (same amount, same recipient).
     */
    private function replay(Transfer $existing, int $amountKobo, int $recipientWalletId): array
    {
        if ($existing->amount_kobo !== $amountKobo || $existing->recipient_wallet_id !== $recipientWalletId) {
            throw TransferException::idempotencyKeyReused();
        }

        return [
            'transfer' => $existing,
            'balance_kobo' => $this->walletService->getBalance($existing->senderWallet),
            'replayed' => true,
        ];
    }

    // =================================================================
    // GET /transfers
    // =================================================================

    /**
     * Transfers the user sent or received, newest first.
     *
     * @param  'sent'|'received'|null  $direction  null = both
     */
    public function listForUser(User $user, ?string $direction, int $perPage = 15): LengthAwarePaginator
    {
        $wallet = $this->walletService->getWallet($user);

        $query = match ($direction) {
            'sent' => Transfer::where('sender_wallet_id', $wallet->id),
            'received' => Transfer::where('recipient_wallet_id', $wallet->id),
            default => Transfer::involvingWallet($wallet->id),
        };

        return $query
            ->with(['senderWallet.user', 'recipientWallet.user']) // avoid N+1 queries
            ->latest('id')
            ->paginate($perPage);
    }

    // =================================================================
    // GET /transfers/{id}
    // =================================================================

    /**
     * One transfer, ONLY if the user is its sender or recipient.
     * Anyone else gets 404 (not 403), so they cannot even learn that
     * a transfer with this ID exists.
     */
    public function findForUser(User $user, int $transferId): Transfer
    {
        $wallet = $this->walletService->getWallet($user);

        return Transfer::involvingWallet($wallet->id)
            ->with(['senderWallet.user', 'recipientWallet.user'])
            ->findOrFail($transferId);
    }
}
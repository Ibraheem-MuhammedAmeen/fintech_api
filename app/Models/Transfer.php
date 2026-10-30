<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money sent from one wallet to another inside the app.
 *
 * One transfer row is linked to:
 *   - 1 ledger transaction (debit sender's wallet account, credit recipient's)
 *   - 2 wallet_transactions rows (a debit for the sender, a credit for the recipient)
 */
class Transfer extends Model
{
    protected $fillable = [
        'reference',
        'sender_wallet_id',
        'recipient_wallet_id',
        'amount_kobo',
        'currency',
        'narration',
        'status',
        'ledger_transaction_id',
        'idempotency_key',
    ];

    // Never send the idempotency key in API responses.
    protected $hidden = [
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
        ];
    }

    public function senderWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'sender_wallet_id');
    }

    public function recipientWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'recipient_wallet_id');
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }

    /**
     * Only transfers this wallet sent OR received.
     * Usage: Transfer::involvingWallet($wallet->id)->get()
     */
    public function scopeInvolvingWallet(Builder $query, int $walletId): Builder
    {
        return $query->where(function (Builder $q) use ($walletId) {
            $q->where('sender_wallet_id', $walletId)
                ->orWhere('recipient_wallet_id', $walletId);
        });
    }
}
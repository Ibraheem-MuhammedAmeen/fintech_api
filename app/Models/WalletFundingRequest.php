<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that "wallet X asked to be funded with N kobo".
 *
 * idempotency_key is the value the client sent in the Idempotency-Key
 * header. If the same key arrives again for the same wallet, we return
 * this request's result instead of funding the wallet a second time.
 */
class WalletFundingRequest extends Model
{
    protected $fillable = [
        'wallet_id',
        'reference',
        'idempotency_key',
        'amount_kobo',
        'ledger_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class);
    }
}
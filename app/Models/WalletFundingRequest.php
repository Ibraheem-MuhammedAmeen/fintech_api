<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletFundingRequest extends Model
{
    protected $fillable = [
        'wallet_id',
        'reference',
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
        return $this->belongsTo(
            LedgerTransaction::class,
            'ledger_transaction_id'
        );
    }
}
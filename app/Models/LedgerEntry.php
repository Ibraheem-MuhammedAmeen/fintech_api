<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'ledger_transaction_id',
        'ledger_account_id',
        'direction',
        'amount_kobo',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_kobo' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(
            LedgerTransaction::class,
            'ledger_transaction_id'
        );
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(
            LedgerAccount::class,
            'ledger_account_id'
        );
    }
}
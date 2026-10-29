<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a ledger transaction: "debit account X by N kobo"
 * or "credit account Y by N kobo".
 *
 * Ledger entries are never edited after they are written. Mistakes are
 * corrected with a new reversal transaction, not by changing old rows.
 * That is why this table has created_at but no updated_at.
 */
class LedgerEntry extends Model
{
    // Turn off Laravel's automatic created_at/updated_at handling,
    // because this table has no updated_at column.
    // We set created_at ourselves when creating an entry.
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
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
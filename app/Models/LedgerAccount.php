<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A "bucket" in the double-entry ledger.
 *
 * - The demo funding source is an ASSET account (DEMO-FUNDING-NGN).
 * - Each user's wallet is a LIABILITY account (WALLET-{id}),
 *   because the money in a wallet is money the platform OWES the user.
 */
class LedgerAccount extends Model
{
    // Codes for accounts that belong to the system, not to a user.
    public const DEMO_FUNDING_NGN = 'DEMO-FUNDING-NGN';

    protected $fillable = [
        'wallet_id',
        'account_code',
        'account_type',
        'currency',
    ];

    // Build the code for a wallet's ledger account, e.g. "WALLET-1".
    public static function walletCode(int $walletId): string
    {
        return 'WALLET-' . $walletId;
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
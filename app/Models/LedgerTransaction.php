<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One financial event (a demo funding, a transfer, a reversal).
 * It has no amount of its own: the amounts live in its LedgerEntry rows,
 * and those rows must always balance (total debits = total credits).
 */
class LedgerTransaction extends Model
{
    protected $fillable = [
        'reference',
        'type',
        'status',
        'description',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function fundingRequest(): HasOne
    {
        return $this->hasOne(WalletFundingRequest::class);
    }
}
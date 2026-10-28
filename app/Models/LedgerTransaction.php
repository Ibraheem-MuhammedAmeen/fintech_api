<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function fundingRequests(): HasMany
    {
        return $this->hasMany(WalletFundingRequest::class);
    }
}
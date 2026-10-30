<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class WalletService
{
    public function getWallet(User $user): Wallet
    {
        return $user->wallet()
            ->firstOrFail();
    }

    public function getBalance(Wallet $wallet): int
    {
        $credits = (int) $wallet->transactions()
            ->where('type', 'credit')
            ->sum('amount_kobo');

        $debits = (int) $wallet->transactions()
            ->where('type', 'debit')
            ->sum('amount_kobo');

        return $credits - $debits;
    }

    /**
     * Same as getBalance(), but uses LOCKING reads ("LOCK IN SHARE MODE").
     *
     * Use this inside DB::transaction() right before SPENDING money.
     *
     * Why: MySQL's normal reads inside a transaction can return an older
     * "snapshot" of the data. A locking read always sees the latest
     * committed rows, so a transfer that waited for another transfer to
     * finish will see the money that transfer already took out.
     */
    public function getLockedBalance(Wallet $wallet): int
    {
        $credits = (int) $wallet->transactions()
            ->where('type', 'credit')
            ->sharedLock()
            ->sum('amount_kobo');

        $debits = (int) $wallet->transactions()
            ->where('type', 'debit')
            ->sharedLock()
            ->sum('amount_kobo');

        return $credits - $debits;
    }

    public function getTransactions(
        Wallet $wallet,
        int $perPage = 15
    ): LengthAwarePaginator {
        return $wallet->transactions()
            ->with('wallet')
            ->latest('id')
            ->paginate($perPage);
    }

    public function getStatement(
        Wallet $wallet,
        string $from,
        string $to,
        int $perPage = 15
    ): LengthAwarePaginator {
        return $wallet->transactions()
            ->with('wallet')
            ->whereBetween('created_at', [
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            ])
            ->latest('id')
            ->paginate($perPage);
    }
}
<?php

namespace App\Services;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use InvalidArgumentException;
use LogicException;

/**
 * The ONE place that writes to the double-entry ledger.
 *
 * Demo funding and transfers both call post(). Keeping the rules here
 * means they are checked the same way for every kind of money movement:
 *   - at least one debit and one credit
 *   - every amount is positive
 *   - all accounts use the same currency
 *   - total debits = total credits
 *
 * Call these methods INSIDE a DB::transaction(), so that if anything
 * fails, the ledger rows are rolled back together with everything else.
 */
class LedgerService
{
    /** The pretend "bank" that demo funding money comes from (an asset). */
    public function demoFundingAccount(): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['account_code' => LedgerAccount::DEMO_FUNDING_NGN],
            ['wallet_id' => null, 'account_type' => 'asset', 'currency' => 'NGN']
        );
    }

    /**
     * A wallet's ledger account (a liability: money the platform owes the user).
     * Created the first time it is needed, reused afterwards.
     */
    public function walletAccount(Wallet $wallet): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['account_code' => LedgerAccount::walletCode($wallet->id)],
            ['wallet_id' => $wallet->id, 'account_type' => 'liability', 'currency' => $wallet->currency]
        );
    }

    /**
     * Write one balanced ledger transaction.
     *
     * @param  'demo_funding'|'transfer'|'reversal'  $type
     * @param  list<array{account: LedgerAccount, direction: 'debit'|'credit', amount_kobo: int}>  $entries
     */
    public function post(string $type, string $reference, ?string $description, array $entries): LedgerTransaction
    {
        // 1. Check everything BEFORE writing anything.
        $this->assertValidEntries($entries);

        // 2. Write the transaction and its entries.
        $ledgerTransaction = LedgerTransaction::create([
            'reference' => $reference,
            'type' => $type,
            'status' => 'posted',
            'description' => $description,
        ]);

        $now = now();

        $ledgerTransaction->entries()->createMany(array_map(fn (array $entry) => [
            'ledger_account_id' => $entry['account']->id,
            'direction' => $entry['direction'],
            'amount_kobo' => $entry['amount_kobo'],
            'created_at' => $now,
        ], $entries));

        // 3. Re-read from the database and check again (belt and braces).
        $this->assertBalancedInDatabase($ledgerTransaction);

        return $ledgerTransaction;
    }

    private function assertValidEntries(array $entries): void
    {
        $debits = 0;
        $credits = 0;
        $currencies = [];

        foreach ($entries as $entry) {
            if (! in_array($entry['direction'], ['debit', 'credit'], true)) {
                throw new InvalidArgumentException("Invalid ledger direction [{$entry['direction']}].");
            }

            if ($entry['amount_kobo'] <= 0) {
                throw new InvalidArgumentException('Ledger entry amounts must be positive.');
            }

            $entry['direction'] === 'debit'
                ? $debits += $entry['amount_kobo']
                : $credits += $entry['amount_kobo'];

            $currencies[$entry['account']->currency] = true;
        }

        if ($debits === 0 || $credits === 0) {
            throw new InvalidArgumentException('A ledger transaction needs at least one debit and one credit.');
        }

        if ($debits !== $credits) {
            throw new LogicException("Unbalanced ledger transaction: debits={$debits}, credits={$credits}.");
        }

        if (count($currencies) > 1) {
            throw new LogicException('All accounts in one ledger transaction must use the same currency.');
        }
    }

    private function assertBalancedInDatabase(LedgerTransaction $ledgerTransaction): void
    {
        $totals = $ledgerTransaction->entries()
            ->selectRaw('direction, SUM(amount_kobo) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        $debits = (int) ($totals['debit'] ?? 0);
        $credits = (int) ($totals['credit'] ?? 0);

        if ($debits === 0 || $debits !== $credits) {
            throw new LogicException(
                "Ledger transaction {$ledgerTransaction->reference} is unbalanced: "
                . "debits={$debits}, credits={$credits}."
            );
        }
    }
}

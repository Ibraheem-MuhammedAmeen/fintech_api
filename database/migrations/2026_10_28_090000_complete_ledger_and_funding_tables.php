<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings ledger_transactions, ledger_entries and wallet_funding_requests
 * up to the full schema needed by demo funding.
 *
 * SAFE BY DESIGN:
 * - It only ADDS columns and indexes that are missing.
 * - If a column already exists (because you created it by hand earlier),
 *   it is left exactly as it is.
 * - It never drops tables, columns or rows.
 * - If it would need to add a required column to a table that already
 *   has rows, it stops with a clear message instead of guessing values.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->completeLedgerTransactions();
        $this->completeLedgerEntries();
        $this->completeWalletFundingRequests();
    }

    private function completeLedgerTransactions(): void
    {
        $this->refuseIfRequiredColumnsMissingOnNonEmptyTable(
            'ledger_transactions',
            ['reference', 'type']
        );

        Schema::table('ledger_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('ledger_transactions', 'reference')) {
                $table->string('reference', 64)->unique();
            }

            if (! Schema::hasColumn('ledger_transactions', 'type')) {
                $table->enum('type', ['transfer', 'demo_funding', 'reversal']);
            }

            if (! Schema::hasColumn('ledger_transactions', 'status')) {
                $table->enum('status', ['posted', 'reversed'])->default('posted');
            }

            if (! Schema::hasColumn('ledger_transactions', 'description')) {
                $table->string('description', 255)->nullable();
            }
        });
    }

    private function completeLedgerEntries(): void
    {
        $this->refuseIfRequiredColumnsMissingOnNonEmptyTable(
            'ledger_entries',
            ['ledger_transaction_id', 'ledger_account_id', 'direction', 'amount_kobo']
        );

        Schema::table('ledger_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('ledger_entries', 'ledger_transaction_id')) {
                $table->foreignId('ledger_transaction_id')
                    ->constrained('ledger_transactions')
                    ->restrictOnDelete();
            }

            if (! Schema::hasColumn('ledger_entries', 'ledger_account_id')) {
                $table->foreignId('ledger_account_id')
                    ->constrained('ledger_accounts')
                    ->restrictOnDelete();
            }

            if (! Schema::hasColumn('ledger_entries', 'direction')) {
                $table->enum('direction', ['debit', 'credit']);
            }

            if (! Schema::hasColumn('ledger_entries', 'amount_kobo')) {
                $table->unsignedBigInteger('amount_kobo');
            }

            if (! Schema::hasColumn('ledger_entries', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
        });
    }

    private function completeWalletFundingRequests(): void
    {
        $this->refuseIfRequiredColumnsMissingOnNonEmptyTable(
            'wallet_funding_requests',
            ['wallet_id', 'reference', 'amount_kobo']
        );

        Schema::table('wallet_funding_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('wallet_funding_requests', 'wallet_id')) {
                $table->foreignId('wallet_id')
                    ->constrained('wallets')
                    ->restrictOnDelete();
            }

            if (! Schema::hasColumn('wallet_funding_requests', 'reference')) {
                $table->string('reference', 64)->unique();
            }

            if (! Schema::hasColumn('wallet_funding_requests', 'amount_kobo')) {
                $table->unsignedBigInteger('amount_kobo');
            }

            if (! Schema::hasColumn('wallet_funding_requests', 'ledger_transaction_id')) {
                $table->foreignId('ledger_transaction_id')
                    ->nullable()
                    ->constrained('ledger_transactions')
                    ->restrictOnDelete();
            }

            // New column: lets us detect a repeated (retried) request.
            if (! Schema::hasColumn('wallet_funding_requests', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable();
            }
        });

        // One idempotency key can only be used once per wallet.
        // (Rows with NULL keys are allowed to repeat.)
        if (! Schema::hasIndex('wallet_funding_requests', 'wfr_wallet_idempotency_unique')) {
            Schema::table('wallet_funding_requests', function (Blueprint $table) {
                $table->unique(
                    ['wallet_id', 'idempotency_key'],
                    'wfr_wallet_idempotency_unique'
                );
            });
        }
    }

    /**
     * Adding a NOT NULL column to a table that already has rows would
     * force us to invent values for those rows. We refuse instead.
     */
    private function refuseIfRequiredColumnsMissingOnNonEmptyTable(string $table, array $columns): void
    {
        $missing = array_filter($columns, fn ($c) => ! Schema::hasColumn($table, $c));

        if ($missing && DB::table($table)->exists()) {
            throw new RuntimeException(sprintf(
                'Table [%s] already has rows but is missing required column(s): %s. '
                . 'Nothing was changed. Back up the table and review its rows before migrating.',
                $table,
                implode(', ', $missing)
            ));
        }
    }

    public function down(): void
    {
        // Only undo what this migration certainly introduced.
        // The other columns may have existed before this migration ran,
        // so we never drop them here (that could destroy real data).
        if (Schema::hasIndex('wallet_funding_requests', 'wfr_wallet_idempotency_unique')) {
            Schema::table('wallet_funding_requests', function (Blueprint $table) {
                $table->dropUnique('wfr_wallet_idempotency_unique');
            });
        }

        if (Schema::hasColumn('wallet_funding_requests', 'idempotency_key')) {
            Schema::table('wallet_funding_requests', function (Blueprint $table) {
                $table->dropColumn('idempotency_key');
            });
        }
    }
};
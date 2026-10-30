<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the `transfers` table up to the full schema needed by the
 * transfer API. The original migration only created id + timestamps.
 *
 * SAFE BY DESIGN (same rules as the demo funding migration):
 * - It only ADDS missing columns and indexes.
 * - Columns that already exist are left exactly as they are.
 * - It never drops tables, columns or rows.
 * - If it would need to add a required column to a table that already
 *   has rows, it stops with a clear message instead of guessing values.
 */
return new class extends Migration
{
    public function up(): void
    {
        // If no earlier migration created the table (e.g. the old
        // create_transfers_table migration is missing on this machine),
        // create a bare one here. The columns are added below.
        if (! Schema::hasTable('transfers')) {
            Schema::create('transfers', function (Blueprint $table) {
                $table->id();
                $table->timestamps();
            });
        }

        $missingRequired = array_filter(
            ['reference', 'sender_wallet_id', 'recipient_wallet_id', 'amount_kobo'],
            fn ($column) => ! Schema::hasColumn('transfers', $column)
        );

        if ($missingRequired && DB::table('transfers')->exists()) {
            throw new RuntimeException(
                'Table [transfers] already has rows but is missing required column(s): '
                . implode(', ', $missingRequired)
                . '. Nothing was changed. Back up the table and review its rows before migrating.'
            );
        }

        Schema::table('transfers', function (Blueprint $table) {
            // Public reference shown to users, e.g. "TR-4af167ac-...".
            if (! Schema::hasColumn('transfers', 'reference')) {
                $table->string('reference', 64)->unique();
            }

            // Who sends and who receives. restrictOnDelete: a wallet with
            // transfer history can never be deleted by accident.
            if (! Schema::hasColumn('transfers', 'sender_wallet_id')) {
                $table->foreignId('sender_wallet_id')
                    ->constrained('wallets')
                    ->restrictOnDelete();
            }

            if (! Schema::hasColumn('transfers', 'recipient_wallet_id')) {
                $table->foreignId('recipient_wallet_id')
                    ->constrained('wallets')
                    ->restrictOnDelete();
            }

            if (! Schema::hasColumn('transfers', 'amount_kobo')) {
                $table->unsignedBigInteger('amount_kobo');
            }

            if (! Schema::hasColumn('transfers', 'currency')) {
                $table->char('currency', 3)->default('NGN');
            }

            // Optional note from the sender, e.g. "Lunch money".
            if (! Schema::hasColumn('transfers', 'narration')) {
                $table->string('narration', 255)->nullable();
            }

            // A transfer is saved only when it succeeds (failures roll back),
            // so it starts as "successful". "reversed" is for the future
            // reversal feature.
            if (! Schema::hasColumn('transfers', 'status')) {
                $table->enum('status', ['successful', 'reversed'])->default('successful');
            }

            // Link to the double-entry ledger record of this transfer.
            if (! Schema::hasColumn('transfers', 'ledger_transaction_id')) {
                $table->foreignId('ledger_transaction_id')
                    ->nullable()
                    ->constrained('ledger_transactions')
                    ->restrictOnDelete();
            }

            // Value of the Idempotency-Key header, to detect retried requests.
            if (! Schema::hasColumn('transfers', 'idempotency_key')) {
                $table->string('idempotency_key', 100)->nullable();
            }
        });

        // One idempotency key can only be used once per sending wallet.
        if (! Schema::hasIndex('transfers', 'transfers_sender_idempotency_unique')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->unique(['sender_wallet_id', 'idempotency_key'], 'transfers_sender_idempotency_unique');
            });
        }

        // Speed up "list my transfers, newest first".
        if (! Schema::hasIndex('transfers', 'transfers_sender_created_index')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->index(['sender_wallet_id', 'created_at'], 'transfers_sender_created_index');
            });
        }

        if (! Schema::hasIndex('transfers', 'transfers_recipient_created_index')) {
            Schema::table('transfers', function (Blueprint $table) {
                $table->index(['recipient_wallet_id', 'created_at'], 'transfers_recipient_created_index');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty: these columns may hold real transfer
        // history, so rolling back must never drop them.
    }
};
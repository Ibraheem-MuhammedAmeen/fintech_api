<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('ledger_accounts')) {
            Schema::create('ledger_accounts', function (Blueprint $table) {
                $table->id();

                $table->foreignId('wallet_id')
                    ->nullable()
                    ->constrained('wallets')
                    ->cascadeOnDelete();

                $table->string('account_code', 80)->unique();

                $table->enum('account_type', [
                    'asset',
                    'liability',
                    'equity',
                ]);

                $table->char('currency', 3)->default('NGN');

                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_accounts');
    }
};
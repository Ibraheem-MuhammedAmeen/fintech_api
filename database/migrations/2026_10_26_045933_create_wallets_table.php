<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('wallets')) {
            Schema::create('wallets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')
                    ->unique()
                    ->constrained()
                    ->cascadeOnDelete();

                $table->string('wallet_number', 10)->unique();
                $table->string('currency', 3)->default('NGN');
                $table->enum('status', [
                    'active',
                    'frozen',
                    'closed'
                ])->default('active');

                $table->timestamps();
            });

            return;
        }

        Schema::table('wallets', function (Blueprint $table) {
            if (!Schema::hasColumn('wallets', 'user_id')) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->unique()
                    ->constrained()
                    ->cascadeOnDelete();
            }

            if (!Schema::hasColumn('wallets', 'wallet_number')) {
                $table->string('wallet_number', 10)->nullable()->unique();
            }

            if (!Schema::hasColumn('wallets', 'currency')) {
                $table->string('currency', 3)->default('NGN');
            }

            if (!Schema::hasColumn('wallets', 'status')) {
                $table->enum('status', [
                    'active',
                    'frozen',
                    'closed'
                ])->default('active');
            }
        });
    }

    public function down(): void
    {
        // Preserve existing wallet data.
    }
};
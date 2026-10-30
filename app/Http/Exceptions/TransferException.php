<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown when a transfer is understood but not allowed
 * (not enough money, sending to yourself, frozen wallet, ...).
 *
 * Laravel calls render() automatically, so the client gets clean JSON.
 * Because the exception is thrown inside DB::transaction(), nothing
 * that was written before it is kept.
 */
class TransferException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function insufficientFunds(): self
    {
        return new self('Insufficient balance for this transfer.', 422);
    }

    public static function selfTransfer(): self
    {
        return new self('You cannot transfer money to your own wallet.', 422);
    }

    public static function senderWalletNotActive(string $status): self
    {
        return new self("Your wallet is {$status}. Only active wallets can send transfers.", 422);
    }

    public static function recipientWalletNotActive(): self
    {
        return new self('The recipient wallet cannot receive transfers right now.', 422);
    }

    public static function currencyMismatch(): self
    {
        return new self('Both wallets must use the same currency.', 422);
    }

    public static function idempotencyKeyReused(): self
    {
        return new self(
            'This Idempotency-Key was already used for a different transfer. '
            . 'Use a new key for a new transfer.',
            409
        );
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}

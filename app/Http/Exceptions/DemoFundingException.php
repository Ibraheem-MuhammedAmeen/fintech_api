<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Thrown when a demo funding request is understood but cannot be done,
 * e.g. the wallet is frozen, or an Idempotency-Key is being reused
 * with a different amount.
 *
 * Laravel automatically calls render() when this exception is not
 * caught, so the client gets a clean JSON error instead of a crash page.
 */
class DemoFundingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function walletNotActive(string $status): self
    {
        return new self("Your wallet is {$status}. Only active wallets can be funded.", 422);
    }

    public static function unsupportedCurrency(string $currency): self
    {
        return new self("Demo funding only supports NGN wallets (this wallet is {$currency}).", 422);
    }

    public static function idempotencyKeyReused(): self
    {
        return new self(
            'This Idempotency-Key was already used for a different amount. '
            . 'Use a new key for a new funding request.',
            409
        );
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
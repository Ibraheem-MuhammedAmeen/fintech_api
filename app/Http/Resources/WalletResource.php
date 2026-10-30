<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The "account number" users share so others can send them money.
            'wallet_number' => $this->wallet_number,
            'currency' => $this->currency,
            'status' => $this->status,
            'balance_kobo' => $this->balance_kobo,
            'created_at' => $this->created_at,
        ];
    }
}
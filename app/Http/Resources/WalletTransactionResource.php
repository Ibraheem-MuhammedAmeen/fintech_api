<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'amount_kobo' => $this->amount_kobo,
            'currency' => $this->wallet?->currency ?? 'NGN',
            'description' => $this->description,
            'created_at' => $this->created_at,
        ];
    }
}
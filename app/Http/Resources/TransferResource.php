<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shapes a Transfer into JSON, from the point of view of the logged-in user:
 * "direction" is "sent" if they sent it, "received" if they received it.
 *
 * Only wallet numbers and names are shown for the other person,
 * never their email or user ID.
 */
class TransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewerWalletId = $request->user()?->wallet?->id;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'direction' => $this->sender_wallet_id === $viewerWalletId ? 'sent' : 'received',
            'amount_kobo' => $this->amount_kobo,
            'currency' => $this->currency,
            'narration' => $this->narration,
            'status' => $this->status,
            'sender' => [
                'wallet_number' => $this->senderWallet?->wallet_number,
                'name' => $this->senderWallet?->user?->name,
            ],
            'recipient' => [
                'wallet_number' => $this->recipientWallet?->wallet_number,
                'name' => $this->recipientWallet?->user?->name,
            ],
            'created_at' => $this->created_at,
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/v1/transfers before the controller runs.
 * If validation fails, Laravel automatically returns 422 with the errors.
 */
class TransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        // auth:sanctum already checked the token. The SENDER wallet always
        // comes from the token, never from the request body.
        return true;
    }

    /**
     * Runs BEFORE the rules:
     * - The idempotency key comes from a header; copy it into the input
     *   so it can be validated like the other fields.
     * - The sender may type the recipient's phone number in any format
     *   ("08031234567", "+234 803 123 4567", "8031234567"). Turn it into
     *   the 10-digit account number. Old non-phone wallet numbers
     *   (e.g. "99yPeuzHz1") are left as they are and still work.
     */
    protected function prepareForValidation(): void
    {
        $data = ['idempotency_key' => $this->header('Idempotency-Key')];

        if (is_string($this->input('recipient_wallet_number'))) {
            $data['recipient_wallet_number'] = PhoneNumber::accountNumberFromInput($this->input('recipient_wallet_number'));
        }

        $this->merge($data);
    }

    public function rules(): array
    {
        return [
            // The recipient's account number (= phone without the leading 0).
            'recipient_wallet_number' => [
                'required',
                'string',
                'max:20',
                'exists:wallets,wallet_number',
            ],
            'amount_kobo' => [
                'required',
                'integer',
                'min:' . config('fintech.transfers.min_kobo'),
                'max:' . config('fintech.transfers.max_kobo'),
            ],
            'narration' => [
                'nullable',
                'string',
                'max:255',
            ],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[A-Za-z0-9_\-:.]+$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_wallet_number.exists' => 'No account was found with this account number.',
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
            'idempotency_key.max' => 'The Idempotency-Key header may not be longer than 100 characters.',
            'idempotency_key.regex' => 'The Idempotency-Key header may only contain letters, numbers, and - _ : .',
        ];
    }
}
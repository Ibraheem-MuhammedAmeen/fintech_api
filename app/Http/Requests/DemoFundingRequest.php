<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the demo funding request before the controller runs.
 * If validation fails, Laravel automatically returns a 422 JSON response.
 */
class DemoFundingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // auth:sanctum has already checked the token. Any logged-in user
        // may fund THEIR OWN wallet; the wallet is never taken from input.
        return true;
    }

    /**
     * The idempotency key comes from a header, not the JSON body.
     * Copy it into the input so we can validate it like any other field.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
        ]);
    }

    public function rules(): array
    {
        return [
            'amount_kobo' => [
                'required',
                'integer',
                'min:' . config('fintech.demo_funding.min_kobo'),
                'max:' . config('fintech.demo_funding.max_kobo'),
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
            'idempotency_key.required' => 'The Idempotency-Key header is required.',
            'idempotency_key.max' => 'The Idempotency-Key header may not be longer than 100 characters.',
            'idempotency_key.regex' => 'The Idempotency-Key header may only contain letters, numbers, and - _ : .',
        ];
    }
}
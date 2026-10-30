<?php

namespace App\Http\Requests;

use App\Models\Wallet;
use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Turn the phone into the standard "08031234567" form BEFORE validation,
     * so "+234 803 123 4567" and "08031234567" count as the same number
     * (and the unique check cannot be dodged by typing it differently).
     * If it is not a valid number, keep what was typed so the error makes sense.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge([
                'phone' => PhoneNumber::normalize((string) $this->input('phone')) ?? $this->input('phone'),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',

            'email' => 'required|email|max:255|unique:users,email',

            // The phone number becomes the account number (OPay style).
            'phone' => [
                'required',
                'string',
                'regex:/^0[789][01]\d{8}$/',
                'unique:users,phone',
                // The account number made from this phone must be free too.
                function (string $attribute, mixed $value, Closure $fail) {
                    if (Wallet::where('wallet_number', PhoneNumber::toAccountNumber((string) $value))->exists()) {
                        $fail('This phone number is already linked to an account.');
                    }
                },
            ],

            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid Nigerian mobile number, e.g. 08031234567.',
            'phone.unique' => 'This phone number is already linked to an account.',
        ];
    }
}
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WalletStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:to',
            ],
            'to' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:from',
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ];
    }
}
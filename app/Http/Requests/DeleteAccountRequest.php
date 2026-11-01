<?php

namespace App\Http\Requests;

class DeleteAccountRequest extends ApiFormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
            'confirmation' => ['required', 'in:DELETE'],
        ];
    }
}

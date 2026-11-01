<?php

namespace App\Http\Requests;

use App\Rules\Username;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => is_string($this->email) ? mb_strtolower(trim($this->email)) : $this->email,
            'username' => is_string($this->username) ? mb_strtolower(trim($this->username)) : $this->username,
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:100'],
            'username' => ['required', 'string', new Username, 'unique:users,username'],
            'email' => ['required', 'string', 'email:rfc,strict', 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'locale' => ['sometimes', 'nullable', 'in:ar,en'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ];
    }
}

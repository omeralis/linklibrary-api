<?php

namespace App\Http\Requests;

use App\Rules\Username;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => mb_strtolower(trim($this->email))]);
        }
        if (is_string($this->username)) {
            $this->merge(['username' => mb_strtolower(trim($this->username))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $id = $this->user()?->id;

        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:100'],
            'username' => ['sometimes', 'string', new Username, Rule::unique('users', 'username')->ignore($id)],
            'email' => ['sometimes', 'string', 'email:rfc,strict', 'max:254', Rule::unique('users', 'email')->ignore($id)],
            'current_password' => ['required_with:email', 'string'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:500'],
            'locale' => ['sometimes', 'in:ar,en'],
            'theme' => ['sometimes', 'in:system,light,dark'],
            'profile_public' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $rules = config('rules.account');

        $rules['email'][] = 'unique:users,email';
        $rules['role_ids'] = ['nullable', 'array'];
        $rules['role_ids.*'] = ['integer', 'exists:roles,id'];

        return $rules;
    }
}

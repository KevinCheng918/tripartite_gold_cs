<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 新增帳號驗證
 */
class StoreAccountRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'account'  => ['required', 'unique:user', config('rules.USER_ACCOUNT_REGEX')],
            'nickname' => 'required|max:100',
            'password' => ['required', config('rules.USER_PASSWORD_REGEX')],
        ];
    }

    public function messages()
    {
        return [
            'account.required' => trans('account.msg.required'),
            'account.unique'   => trans('account.msg.unique'),
            'account.regex'    => trans('account.msg.regex_account'),
            'nickname.required' => trans('account.msg.required'),
            'nickname.max'     => trans('account.msg.max_string', ['value' => '100']),
            'password.required' => trans('account.msg.required'),
            'password.regex'    => trans('account.msg.regex_password'),
        ];
    }
}

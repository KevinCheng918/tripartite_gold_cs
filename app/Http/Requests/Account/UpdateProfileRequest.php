<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 個人資訊修改驗證（暱稱、密碼）
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * 密碼 regex 內含 `|`，規則必須用陣列形式，
     * 若用 pipe 字串串接會被 Laravel 從中間切斷導致驗證必定失敗。
     *
     * @return array
     */
    public function rules()
    {
        return [
            'nickname' => 'sometimes|string|max:100',
            'password' => ['sometimes', 'nullable', config('rules.USER_PASSWORD_REGEX')],
        ];
    }

    public function messages()
    {
        return [
            'nickname.max'   => trans('account.msg.max_string', ['value' => '100']),
            'password.regex' => trans('account.msg.regex_password'),
        ];
    }
}

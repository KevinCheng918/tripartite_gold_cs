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
            'nickname'          => 'sometimes|string|max:100',
            'password'          => ['sometimes', 'nullable', config('rules.USER_PASSWORD_REGEX')],
            'telegram_username' => ['sometimes', 'nullable', 'string', 'max:50', config('rules.TELEGRAM_USERNAME_REGEX')],
            /*
             * 閒置多久自動登出。
             *
             * ⚠ **用 `in:` 限定在選項裡**，不要只驗 integer —— 這一欄之後
             * 會被前端拿去倒數，塞一個 1 分鐘進來等於讓那個人一直被登出。
             * 選項只有一份，在 `constants.USER.SESSION_TIMEOUT.OPTIONS`。
             */
            'session_timeout' => [
                'sometimes',
                'integer',
                'in:' . implode(',', (array) config('constants.USER.SESSION_TIMEOUT.OPTIONS')),
            ],
        ];
    }

    public function messages()
    {
        return [
            'nickname.max'            => trans('account.msg.max_string', ['value' => '100']),
            'password.regex'          => trans('account.msg.regex_password'),
            'telegram_username.regex' => trans('account.msg.telegram_username_invalid'),
            'telegram_username.max'   => trans('account.msg.max_string', ['value' => '50']),
        ];
    }
}

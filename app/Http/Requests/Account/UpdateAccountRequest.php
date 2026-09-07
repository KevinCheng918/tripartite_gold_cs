<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 更新帳號驗證
 */
class UpdateAccountRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $userId = $this->route('user');

        return [
            'nickname' => 'sometimes|max:100',
            // Telegram 對話署名。會直接附在送給客戶的訊息結尾，長度壓短一點
            'telegram_nickname' => 'nullable|string|max:30',
            'account'  => ['sometimes', 'max:100', Rule::unique('user', 'account')->ignore($userId)],
            'password' => ['sometimes', 'nullable', config('rules.USER_PASSWORD_REGEX')],
            'level'          => 'sometimes|integer|in:0,1,2,3,4',
            'status'         => 'sometimes|integer|in:0,1,2',
            'project_ids'    => 'nullable|array',
            'project_ids.*'  => 'integer',
            'hired_at'       => 'nullable|date',
            'equipments'     => 'nullable|array',
        ];
    }

    public function messages()
    {
        return [
            'nickname.max'     => trans('account.msg.max_string', ['value' => '100']),
            'telegram_nickname.max' => trans('account.msg.telegram_nickname_max', ['value' => '30']),
            'account.unique'   => trans('account.msg.unique'),
            'password.regex'   => trans('account.msg.regex_password'),
            'status.in'        => trans('account.msg.invalid_status'),
        ];
    }
}

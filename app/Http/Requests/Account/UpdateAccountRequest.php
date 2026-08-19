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
            'account'  => ['sometimes', 'max:100', Rule::unique('user', 'account')->ignore($userId)],
            'password' => 'sometimes|nullable|' . config('rules.USER_PASSWORD_REGEX'),
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
            'account.unique'   => trans('account.msg.unique'),
            'password.regex'   => trans('account.msg.regex_password'),
            'status.in'        => trans('account.msg.invalid_status'),
        ];
    }
}

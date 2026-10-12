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
            'telegram_username' => ['nullable', 'string', 'max:50', config('rules.TELEGRAM_USERNAME_REGEX')],
            'account'  => ['sometimes', 'max:100', Rule::unique('user', 'account')->ignore($userId)],
            'password' => ['sometimes', 'nullable', config('rules.USER_PASSWORD_REGEX')],
            'level'          => 'sometimes|integer|in:0,1,2,3,4',
            'status'         => 'sometimes|integer|in:0,1,2',
            'project_ids'    => 'nullable|array',
            'project_ids.*'  => 'integer',
            'hired_at'       => 'nullable|date',
            'equipments'     => 'nullable|array',

            /*
             * 出勤模式。輪班＝吃排班，固定時間＝吃下面那三欄。
             *
             * ⚠ 時間與上班日用 `required_if`：選了固定時間卻沒填，
             * 那個人就會變成「永遠算不出遲到早退」的狀態 ——
             * 跟沒設定一樣，但使用者以為自己設定好了。
             */
            'work_mode'       => 'sometimes|integer|in:1,2',
            'work_start_time' => 'nullable|required_if:work_mode,2|date_format:H:i',
            'work_end_time'   => 'nullable|required_if:work_mode,2|date_format:H:i',
            'work_days'       => 'nullable|required_if:work_mode,2|array',
            'work_days.*'     => 'integer|between:1,7',
            'show_in_shift'   => 'sometimes|boolean',
        ];
    }

    public function messages()
    {
        return [
            'nickname.max'     => trans('account.msg.max_string', ['value' => '100']),
            'telegram_nickname.max' => trans('account.msg.telegram_nickname_max', ['value' => '30']),
            'telegram_username.max'   => trans('account.msg.max_string', ['value' => '50']),
            'telegram_username.regex' => trans('account.msg.telegram_username_invalid'),
            'account.unique'   => trans('account.msg.unique'),
            'password.regex'   => trans('account.msg.regex_password'),
            'status.in'        => trans('account.msg.invalid_status'),
            'work_start_time.required_if' => trans('account.msg.work_time_required'),
            'work_end_time.required_if'   => trans('account.msg.work_time_required'),
            'work_days.required_if'       => trans('account.msg.work_days_required'),
            'work_start_time.date_format' => trans('account.msg.work_time_invalid'),
            'work_end_time.date_format'   => trans('account.msg.work_time_invalid'),
        ];
    }
}

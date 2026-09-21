<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新內部支援群組設定
 */
class UpdateSupportRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // 群組的 chat_id 是負數，所以不能用 integer 規則擋掉負號。
            //
            // unique:telegram_group —— 支援群組不能拿客戶對話的群組來用：
            // webhook 會先判斷「這則來自支援群組」就攔掉，那個群組的客戶訊息
            // 會全部被當成自己人的討論，不存訊息也不自動回覆，而且完全不報錯
            'chat_id'               => [
                'nullable',
                'string',
                config('rules.TELEGRAM_CHAT_ID_REGEX'),
                'unique:telegram_group,chat_id',
            ],
            'system_id'             => 'nullable|integer|exists:system,id',
            'remind_first_minutes'  => 'required|integer|min:1|max:1440',
            'remind_second_minutes' => 'required|integer|min:1|max:1440',
        ];
    }

    public function messages()
    {
        return [
            'chat_id.regex'                  => trans('setting.msg.chat_id_invalid'),
            'chat_id.unique'                 => trans('setting.msg.chat_id_is_customer'),
            'system_id.exists'               => trans('setting.msg.system_not_found'),
            'remind_first_minutes.required'  => trans('setting.msg.remind_required'),
            'remind_first_minutes.min'       => trans('setting.msg.remind_invalid'),
            'remind_first_minutes.max'       => trans('setting.msg.remind_invalid'),
            'remind_second_minutes.required' => trans('setting.msg.remind_required'),
            'remind_second_minutes.min'      => trans('setting.msg.remind_invalid'),
            'remind_second_minutes.max'      => trans('setting.msg.remind_invalid'),
        ];
    }
}

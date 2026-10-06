<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新內部支援群組（chat_id 與用哪個 Bot）
 *
 * ⚠ 刻意只管這兩個欄位。設定頁分四個分頁，各存自己的那幾個 ——
 * 一支 Request 吃全部欄位的話，存「支援群組」那個分頁會把「求助單提醒」
 * 的值一起寫掉（沒填的欄位會變成空值）。
 */
class UpdateGroupRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            /*
             * 群組的 chat_id 是負數，所以不能用 integer 規則擋掉負號。
             *
             * unique:telegram_group —— 支援群組不能拿客戶對話的群組來用：
             * webhook 會先判斷「這則來自支援群組」就攔掉，那個群組的客戶訊息
             * 會全部被當成自己人的討論，不存訊息也不自動回覆，而且完全不報錯。
             */
            'chat_id' => [
                'nullable',
                'string',
                config('rules.TELEGRAM_CHAT_ID_REGEX'),
                'unique:telegram_group,chat_id',
            ],
            'system_id' => 'nullable|integer|exists:system,id',
        ];
    }

    public function messages()
    {
        return [
            'chat_id.regex'    => trans('notification.msg.chat_id_invalid'),
            'chat_id.unique'   => trans('notification.msg.chat_id_is_customer'),
            'system_id.exists' => trans('notification.msg.system_not_found'),
        ];
    }
}

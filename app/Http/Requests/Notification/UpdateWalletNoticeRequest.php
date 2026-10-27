<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新錢包轉帳通知的設定
 */
class UpdateWalletNoticeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'wallet_enabled' => 'nullable|boolean',

            /*
             * ⚠ 有上限：每個錢包每分鐘各一次 TronGrid 呼叫，
             * 無限加會把免費額度打爆（而且症狀是「通知安靜地不來」）。
             */
            'wallet_addresses'   => 'nullable|array|max:' . config('rules.WALLET_ADDRESS_MAX'),
            'wallet_addresses.*' => 'array',

            /*
             * ⚠ **地址格式一定要在存檔當下擋**。填錯的話 TronGrid 會每分鐘
             * 回一次錯，而且沒有人會發現 —— 通知就只是安靜地不會來。
             *
             * pattern 跟 `TronGridService::isValidAddress()` 共用
             * `config('rules.TRON_ADDRESS_PATTERN')`，寫成兩份遲早會分岔。
             */
            'wallet_addresses.*.address' => 'required|string|regex:' . config('rules.TRON_ADDRESS_PATTERN'),
            'wallet_addresses.*.label'   => 'nullable|string|max:50',

            /*
             * 可以勾多位。全部取消勾選 ＝ 不通知任何人
             * （鏈上紀錄照樣會記，只是沒人會被私訊）。
             *
             * ⚠ exists 查的是 `user` 表（不是 `users`）—— 這個專案的使用者表
             * 沒有複數 s，寫錯會變成「資料表不存在」的 500。
             */
            'wallet_notice_user_ids'   => 'nullable|array',
            'wallet_notice_user_ids.*' => 'integer|exists:user,id',

            /*
             * ⚠ 可以填 0（＝全部都通知），所以是 min:0 不是 min:0.000001。
             * 上限擋手滑：填太大等於把通知整個關掉卻看不出來。
             */
            'wallet_min_amount' => 'nullable|numeric|min:0|max:' . config('rules.WALLET_MIN_AMOUNT_MAX'),
        ];
    }

    public function messages()
    {
        return [
            'wallet_addresses.max'                 => trans('notification.msg.wallet_address_too_many'),
            'wallet_addresses.*.address.required'  => trans('notification.msg.wallet_address_required'),
            'wallet_addresses.*.address.regex'     => trans('notification.msg.wallet_address_invalid'),
            'wallet_addresses.*.label.max'         => trans('notification.msg.wallet_label_too_long'),
            'wallet_notice_user_ids.array'         => trans('notification.msg.wallet_user_not_found'),
            'wallet_notice_user_ids.*.integer'     => trans('notification.msg.wallet_user_not_found'),
            'wallet_notice_user_ids.*.exists'      => trans('notification.msg.wallet_user_not_found'),
            'wallet_min_amount.numeric'            => trans('notification.msg.wallet_min_amount_invalid'),
            'wallet_min_amount.min'                => trans('notification.msg.wallet_min_amount_invalid'),
            'wallet_min_amount.max'                => trans('notification.msg.wallet_min_amount_invalid'),
        ];
    }
}

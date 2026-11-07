<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新「自動回覆自動恢復」的設定
 *
 * 只有兩個欄位：總開關、與 modal 的預填分鐘數。
 *
 * ⚠ **這裡沒有「靜置幾分鐘」**：真正生效的分鐘數由關閉的人在聊天視窗的
 * modal 當場填，存在每一筆關閉紀錄上（`auto_reply_resume.quiet_minutes`）。
 */
class UpdateAutoReplyResumeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            // checkbox 沒勾時整個欄位不會送上來，所以是 nullable 而不是 required
            'enabled' => 'nullable|boolean',

            /*
             * modal 的預填值。
             *
             * ⚠ 範圍跟 modal 送出時的驗證**用同一組 config**
             * （`rules.AUTO_REPLY_RESUME_*_MINUTES`）—— 各寫一次魔術數字的話，
             * 設定頁存得進去、客服在 modal 用那個值卻被擋下來。
             */
            'default_minutes' => 'required|integer'
                . '|min:' . (int) config('rules.AUTO_REPLY_RESUME_MIN_MINUTES')
                . '|max:' . (int) config('rules.AUTO_REPLY_RESUME_MAX_MINUTES'),
        ];
    }

    public function messages()
    {
        return [
            'default_minutes.required' => trans('notification.msg.resume_minutes_required'),
            'default_minutes.integer'  => trans('notification.msg.resume_minutes_range', [
                'min' => (int) config('rules.AUTO_REPLY_RESUME_MIN_MINUTES'),
                'max' => (int) config('rules.AUTO_REPLY_RESUME_MAX_MINUTES'),
            ]),
            'default_minutes.min'      => trans('notification.msg.resume_minutes_range', [
                'min' => (int) config('rules.AUTO_REPLY_RESUME_MIN_MINUTES'),
                'max' => (int) config('rules.AUTO_REPLY_RESUME_MAX_MINUTES'),
            ]),
            'default_minutes.max'      => trans('notification.msg.resume_minutes_range', [
                'min' => (int) config('rules.AUTO_REPLY_RESUME_MIN_MINUTES'),
                'max' => (int) config('rules.AUTO_REPLY_RESUME_MAX_MINUTES'),
            ]),
        ];
    }
}

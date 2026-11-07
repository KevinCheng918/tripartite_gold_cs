<?php

namespace App\Http\Requests\TelegramChat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 切換對話的自動回覆開關
 *
 * 關閉時多帶兩個欄位：關閉意圖（暫時／永久）與靜置分鐘數 ——
 * 自動恢復靠它們判斷這一筆該不該被勾回來。
 * 見 .claude/knowledge/features/auto-reply-resume.md
 */
class ToggleAutoReplyRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $modes = (array) config('constants.AUTO_REPLY_RESUME.MODE');

        return [
            'group_id' => 'required|integer',
            'enabled'  => 'required|boolean',

            /*
             * 關閉意圖與靜置分鐘數。
             *
             * ⚠ **兩個都是 `nullable`，刻意不用 `required_if`**：自動恢復功能
             * 關著時 modal 不會出現，那一次送出本來就沒有這兩個值 ——
             * 寫成必填會讓「功能關著時連開關都按不動」。
             *
             * 沒帶時由 `AutoReplyResumeService::recordOff()` 補預設
             * （功能關著時它連紀錄都不建）。這裡只負責「帶了就必須合法」:
             * 填 1 分鐘會讓 AI 在人工處理的中途插話，填一週等於這筆永遠不恢復。
             */
            'mode' => 'nullable|integer|in:' . implode(',', array_values($modes)),

            'quiet_minutes' => [
                'nullable',
                'integer',
                'min:' . (int) config('rules.AUTO_REPLY_RESUME_MIN_MINUTES'),
                'max:' . (int) config('rules.AUTO_REPLY_RESUME_MAX_MINUTES'),
            ],
        ];
    }

    public function messages()
    {
        /*
         * integer / min / max 共用同一句「請填 5～1440 之間」—— 分成三句的話，
         * 填 2000 的人看到「不可大於 1440」還要自己猜下限是多少。
         */
        $range = trans('telegram_chat.msg.quiet_minutes_range', [
            'min' => (int) config('rules.AUTO_REPLY_RESUME_MIN_MINUTES'),
            'max' => (int) config('rules.AUTO_REPLY_RESUME_MAX_MINUTES'),
        ]);

        return [
            'group_id.required'     => trans('telegram_chat.msg.group_not_found'),
            'enabled.required'      => trans('telegram_chat.msg.required'),
            'mode.in'               => trans('telegram_chat.msg.off_mode_invalid'),
            'quiet_minutes.integer' => $range,
            'quiet_minutes.min'     => $range,
            'quiet_minutes.max'     => $range,
        ];
    }
}

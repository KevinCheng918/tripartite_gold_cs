<?php

namespace App\Http\Requests\TelegramChat;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Telegram 傳送檔案驗證
 *
 * 圖片走 ajax-send-image（有 5MB 與尺寸限制），這裡收的是圖片以外的一般檔案。
 */
class SendFileRequest extends FormRequest
{
    /** @var int 檔案大小上限（KB），Telegram Bot API 上傳上限為 50MB */
    public static $maxKb = 51200;

    public function authorize()
    {
        return true;
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            'group_id' => 'required|integer|exists:telegram_group,id',
            'file'     => ['required', 'file', 'max:' . self::$maxKb, $this->blockedExtensionRule()],
            'caption'  => 'nullable|string|max:1024',
        ];
    }

    /**
     * @return \Closure
     */
    protected function blockedExtensionRule()
    {
        return function ($attribute, $value, $fail) {
            if (!$value || !method_exists($value, 'getClientOriginalExtension')) {
                return;
            }

            $ext = strtolower((string) $value->getClientOriginalExtension());
            if (in_array($ext, config('rules.UPLOAD_BLOCKED_EXTENSIONS'), true)) {
                $fail(trans('telegram_chat.msg.file_type_blocked'));
            }
        };
    }

    public function messages()
    {
        return [
            'group_id.required' => trans('telegram_chat.msg.required'),
            'group_id.exists'   => trans('telegram_chat.msg.group_not_found'),
            'file.required'     => trans('telegram_chat.msg.file_required'),
            'file.max'          => trans('telegram_chat.msg.file_too_large', ['value' => self::$maxKb / 1024]),
        ];
    }
}

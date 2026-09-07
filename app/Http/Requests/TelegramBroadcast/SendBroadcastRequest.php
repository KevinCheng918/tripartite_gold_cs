<?php

namespace App\Http\Requests\TelegramBroadcast;

use App\Http\Requests\Concerns\BlocksExecutableUploads;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 群發公告發送驗證
 *
 * scheduled_at 有值就是預約發送，空值為立即發送。
 */
class SendBroadcastRequest extends FormRequest
{
    use BlocksExecutableUploads;

    /** @var int 單次可帶的圖片張數（Telegram sendMediaGroup 上限） */
    public static $maxImages = 10;

    /** @var int 單張圖片大小上限（KB） */
    public static $imageMaxKb = 5120;

    /** @var int 單次可帶的檔案數 */
    public static $maxFiles = 10;

    /** @var int 單個檔案大小上限（KB），Telegram Bot API 上傳上限為 50MB */
    public static $fileMaxKb = 51200;

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
            'content'      => 'required|string|max:4096',
            'target_type'  => 'required|integer|in:1,2',
            'group_ids'    => 'nullable|array',
            'group_ids.*'  => 'integer',
            'images'       => 'nullable|array|max:' . self::$maxImages,
            'images.*'     => 'image|max:' . self::$imageMaxKb,
            'files'        => 'nullable|array|max:' . self::$maxFiles,
            'files.*'      => ['file', 'max:' . self::$fileMaxKb, $this->blockedExtensionRule('broadcast.msg.file_type_blocked')],
            // after:now 擋掉過去時間，否則排程下一輪就會立刻送出，等同沒預約
            'scheduled_at' => 'nullable|date_format:Y-m-d H:i|after:now',
        ];
    }

    public function messages()
    {
        return [
            'content.required'          => trans('broadcast.msg.content_required'),
            'content.max'               => trans('broadcast.msg.content_too_long'),
            'images.max'                => trans('broadcast.msg.too_many_images', ['value' => self::$maxImages]),
            'images.*.image'            => trans('broadcast.msg.image_invalid'),
            'images.*.max'              => trans('broadcast.msg.image_too_large', ['value' => self::$imageMaxKb / 1024]),
            'files.max'                 => trans('broadcast.msg.too_many_files', ['value' => self::$maxFiles]),
            'files.*.max'               => trans('broadcast.msg.file_too_large', ['value' => self::$fileMaxKb / 1024]),
            'scheduled_at.date_format'  => trans('broadcast.msg.schedule_invalid'),
            'scheduled_at.after'        => trans('broadcast.msg.schedule_past'),
        ];
    }
}

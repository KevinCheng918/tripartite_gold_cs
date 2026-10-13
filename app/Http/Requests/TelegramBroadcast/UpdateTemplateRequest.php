<?php

namespace App\Http\Requests\TelegramBroadcast;

use App\Http\Requests\Concerns\BlocksExecutableUploads;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 修改公告範本
 *
 * ⚠ 跟新增的差別只有附件：這裡多一個 `keep_images` / `keep_files`，
 * 讓前端表達「原本那幾個留著、這幾個不要了」。沒有它的話，每次編輯標題
 * 都得把附件重傳一輪。
 */
class UpdateTemplateRequest extends FormRequest
{
    use BlocksExecutableUploads;

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
            'title'    => 'required|string|max:' . StoreTemplateRequest::$titleMax,
            'content'  => 'required|string|max:4096',

            /*
             * 要保留的既有附件（前端把原本那份陣列扣掉使用者按叉掉的，整包送回）。
             * `present` 而不是 `required`：全部移除是合法的，那時會是空陣列。
             */
            'keep_images'   => 'present|array',
            'keep_images.*' => 'array',
            'keep_files'    => 'present|array',
            'keep_files.*'  => 'array',

            'images'   => 'nullable|array|max:' . SendBroadcastRequest::$maxImages,
            'images.*' => 'image|max:' . SendBroadcastRequest::$imageMaxKb,
            'files'    => 'nullable|array|max:' . SendBroadcastRequest::$maxFiles,
            'files.*'  => ['file', 'max:' . SendBroadcastRequest::$fileMaxKb, $this->blockedExtensionRule('broadcast.msg.file_type_blocked')],
        ];
    }

    public function messages()
    {
        return [
            'title.required'   => trans('broadcast.msg.title_required'),
            'title.max'        => trans('broadcast.msg.title_too_long', ['value' => StoreTemplateRequest::$titleMax]),
            'content.required' => trans('broadcast.msg.content_required'),
            'content.max'      => trans('broadcast.msg.content_too_long'),
            'images.max'       => trans('broadcast.msg.too_many_images', ['value' => SendBroadcastRequest::$maxImages]),
            'images.*.image'   => trans('broadcast.msg.image_invalid'),
            'images.*.max'     => trans('broadcast.msg.image_too_large', ['value' => SendBroadcastRequest::$imageMaxKb / 1024]),
            'files.max'        => trans('broadcast.msg.too_many_files', ['value' => SendBroadcastRequest::$maxFiles]),
            'files.*.max'      => trans('broadcast.msg.file_too_large', ['value' => SendBroadcastRequest::$fileMaxKb / 1024]),
        ];
    }
}

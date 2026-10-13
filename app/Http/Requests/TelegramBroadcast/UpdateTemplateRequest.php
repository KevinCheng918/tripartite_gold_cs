<?php

namespace App\Http\Requests\TelegramBroadcast;

use App\Http\Requests\Concerns\BlocksExecutableUploads;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 修改公告範本
 *
 * ⚠ 跟新增的差別只有附件：這裡多一組「要留下哪幾個」，讓前端表達
 * 「原本那幾個留著、這幾個不要了」。沒有它的話，每次編輯標題都得把附件重傳一輪。
 *
 * ⚠ **收的是索引不是路徑**。附件路徑最後會進 `sendDocument()`（吃本地絕對路徑），
 * 收路徑等於讓人把任意檔案寫進範本，下次發送就送給所有客人了。
 * 索引只用來從**資料庫裡那一筆範本**挑出要留的項目。
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
            /*
             * ⚠ 唯一性要**排除自己**，不然改標題時會被自己的編號擋下來。
             * `route('template')` 是 route model binding 綁進來的那一筆。
             */
            'code' => [
                'required',
                'string',
                'max:' . StoreTemplateRequest::$codeMax,
                Rule::unique('broadcast_template', 'code')->ignore($this->route('template')),
            ],
            'title'    => 'required|string|max:' . StoreTemplateRequest::$titleMax,
            'content'  => 'required|string|max:4096',

            /*
             * 要留下的既有附件索引。
             *
             * ⚠ `nullable` 而不是 `present`：FormData 送不出空陣列，
             * 所以「全部移除」就是整個欄位不送 —— 沒送＝一個都不留。
             */
            'keep_image_indexes'   => 'nullable|array',
            'keep_image_indexes.*' => 'integer|min:0',
            'keep_file_indexes'    => 'nullable|array',
            'keep_file_indexes.*'  => 'integer|min:0',

            'images'   => 'nullable|array|max:' . SendBroadcastRequest::$maxImages,
            'images.*' => 'image|max:' . SendBroadcastRequest::$imageMaxKb,
            'files'    => 'nullable|array|max:' . SendBroadcastRequest::$maxFiles,
            'files.*'  => ['file', 'max:' . SendBroadcastRequest::$fileMaxKb, $this->blockedExtensionRule('broadcast.msg.file_type_blocked')],
        ];
    }

    public function messages()
    {
        return [
            'code.required'    => trans('broadcast.msg.code_required'),
            'code.max'         => trans('broadcast.msg.code_too_long', ['value' => StoreTemplateRequest::$codeMax]),
            'code.unique'      => trans('broadcast.msg.code_duplicated'),
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

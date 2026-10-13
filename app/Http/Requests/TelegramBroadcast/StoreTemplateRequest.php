<?php

namespace App\Http\Requests\TelegramBroadcast;

use App\Http\Requests\Concerns\BlocksExecutableUploads;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 建檔公告範本
 *
 * ⚠ 上限刻意**沿用 `SendBroadcastRequest` 的那幾個常數**，不自己訂一套：
 * 範本最終要走發送那條路，這裡放行比較寬的話，建完檔才發現送不出去。
 */
class StoreTemplateRequest extends FormRequest
{
    use BlocksExecutableUploads;

    /** @var int 標題長度上限，對齊 DB 的 string(100) */
    public static $titleMax = 100;

    /** @var int 公告編號長度上限，對齊 DB 的 string(32) */
    public static $codeMax = 32;

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
             * ⚠ 編號要唯一 —— 它的用途就是唯一識別，重複的編號等於沒有編號。
             * DB 那一欄是 nullable（既有資料沒有編號），但表單這裡必填。
             */
            'code'     => 'required|string|max:' . self::$codeMax . '|unique:broadcast_template,code',
            'title'    => 'required|string|max:' . self::$titleMax,
            'content'  => 'required|string|max:4096',
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
            'code.max'         => trans('broadcast.msg.code_too_long', ['value' => self::$codeMax]),
            'code.unique'      => trans('broadcast.msg.code_duplicated'),
            'title.required'   => trans('broadcast.msg.title_required'),
            'title.max'        => trans('broadcast.msg.title_too_long', ['value' => self::$titleMax]),
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

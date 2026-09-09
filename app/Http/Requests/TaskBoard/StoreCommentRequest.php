<?php

namespace App\Http\Requests\TaskBoard;

use App\Http\Requests\TaskBoard\Concerns\HasAttachmentRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 新增留言驗證
 *
 * images 欄位沿用舊名，實際上收的是各類附件（不限圖片），
 * 與任務附件共用 HasAttachmentRules 的大小上限與副檔名黑名單。
 */
class StoreCommentRequest extends FormRequest
{
    use HasAttachmentRules;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'content'  => 'required|string|max:2000',
            'images'   => 'nullable|array',
            'images.*' => $this->attachmentRules(),
        ];
    }

    public function messages()
    {
        return [
            'images.*.max' => $this->attachmentMaxMessage(),
        ];
    }
}

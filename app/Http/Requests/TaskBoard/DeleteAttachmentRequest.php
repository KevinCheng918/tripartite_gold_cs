<?php

namespace App\Http\Requests\TaskBoard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 刪除任務單一附件
 *
 * 傳 URL 而非陣列索引：索引在多人同時操作時會錯位，
 * 可能刪掉別人剛上傳的檔案。
 */
class DeleteAttachmentRequest extends FormRequest
{
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
            'url' => 'required|string|max:2000',
        ];
    }

    public function messages()
    {
        return [
            'url.required' => trans('task_board.msg.attachment_url_required'),
        ];
    }
}

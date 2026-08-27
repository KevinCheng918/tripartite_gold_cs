<?php

namespace App\Http\Requests\TaskBoard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新留言驗證（僅內容，圖片不可異動）
 */
class UpdateCommentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'content' => 'required|string|max:2000',
        ];
    }

    public function messages()
    {
        return [
            'content.required' => trans('task_board.msg.comment_content_required'),
            'content.max'      => trans('task_board.msg.comment_content_max', ['value' => '2000']),
        ];
    }
}

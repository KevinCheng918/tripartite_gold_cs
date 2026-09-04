<?php

namespace App\Http\Requests\TaskBoard;

use App\Http\Requests\TaskBoard\Concerns\HasAttachmentRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 任務描述編輯器的檔案上傳驗證
 */
class UploadEditorFileRequest extends FormRequest
{
    use HasAttachmentRules;

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
            'file' => array_merge(['required'], $this->attachmentRules()),
        ];
    }

    public function messages()
    {
        return [
            'file.required' => trans('task_board.msg.file_required'),
            'file.max'      => $this->attachmentMaxMessage(),
        ];
    }
}

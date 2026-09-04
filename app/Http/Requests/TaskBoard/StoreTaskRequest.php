<?php

namespace App\Http\Requests\TaskBoard;

use App\Http\Requests\TaskBoard\Concerns\HasAttachmentRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 新增任務驗證
 *
 * images 欄位沿用舊名，實際上收的是各類附件（不限圖片）。
 */
class StoreTaskRequest extends FormRequest
{
    use HasAttachmentRules;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'project_id'  => 'required|integer|exists:project,id',
            'station_id'  => 'nullable|integer|exists:station,id',
            'title'       => 'required|string|max:200',
            'description' => 'nullable|string',
            'priority'    => 'nullable|integer|in:1,2,3,4',
            'assignee_ids'   => 'nullable|array',
            'assignee_ids.*' => 'integer',
            'due_date'    => 'nullable|date',
            'images'      => 'nullable|array',
            'images.*'    => $this->attachmentRules(),
        ];
    }

    public function messages()
    {
        return [
            'images.*.max' => $this->attachmentMaxMessage(),
        ];
    }
}

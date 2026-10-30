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
            /*
             * 參與人（需求方 2026-10-30）—— 一起處理或需要知道，但不負責結案。
             *
             * ⚠ 刻意**不驗 `exists:user,id`**，跟 `assignee_ids` 一致：
             * 這份清單是從後端渲染的勾選框來的，而離職的人仍然可能留在
             * 舊卡片上 —— 加了 exists 會讓「編輯一張老卡片」因為上面某個
             * 已離職的人而整張存不起來。
             */
            'participant_ids'   => 'nullable|array',
            'participant_ids.*' => 'integer',
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

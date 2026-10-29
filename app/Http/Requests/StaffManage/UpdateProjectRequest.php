<?php

namespace App\Http\Requests\StaffManage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name'        => 'required|string|max:100|unique:project,name,' . $this->route('project')->id,
            'description' => 'nullable|string|max:500',
            'status'      => 'sometimes|integer|in:0,1',
            /*
             * 編輯時可以一併換負責人（需求方 2026-10-30）。
             *
             * ⚠ **`sometimes` 不可以漏**：沒有 `project.assign_owner` 權限的人
             * 表單裡根本沒有這個欄位，送上來的 payload 不含 `owner_id` ——
             * 少了 `sometimes`，「沒送」會被當成「送了 null」而把負責人清空。
             * 「沒送＝不要動」與「送 null＝清空」是兩件事。
             *
             * ⚠ `deleted_at,NULL`：`user` 是軟刪除的，不加就換得到離職的人。
             *
             * ⚠ 「有沒有參與這個專案」不在這裡驗（`project_ids` 是 JSON 欄位，
             * 不是 validation rule 表達得了的）—— 那一關在
             * `ProjectOwnerService::guardParticipation()`，跟「變更負責人」
             * 走同一支，不會有兩份實作。
             */
            'owner_id'    => 'sometimes|nullable|integer|exists:user,id,deleted_at,NULL',
        ];
    }
}

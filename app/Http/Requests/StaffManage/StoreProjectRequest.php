<?php

namespace App\Http\Requests\StaffManage;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name'        => 'required|string|max:100|unique:project,name',
            'description' => 'nullable|string|max:500',
            /*
             * 新增時可以一併指定負責人（需求方 2026-10-30 裁示：可選、允許留空）。
             *
             * ⚠ **新增這條路徑不驗「有沒有參與這個專案」**：那一刻專案還不存在，
             * 沒有人勾得了參與它 —— 驗了的話下拉只剩管理者，這個欄位等於沒用。
             * 存檔後專案卡片會標紅字提醒去帳號管理把他加入參與專案，
             * 之後走「變更負責人」就會被擋。見 features/project-owner.md。
             *
             * ⚠ `deleted_at,NULL`：`user` 是軟刪除的，不加就指派得了離職的人。
             */
            'owner_id'    => 'nullable|integer|exists:user,id,deleted_at,NULL',
        ];
    }
}

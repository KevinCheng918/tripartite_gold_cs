<?php

namespace App\Http\Requests\StaffManage;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 指派專案負責人
 *
 * ⚠ **這裡只驗「這個帳號存在、而且還沒離職」**。「他有沒有參與這個專案」
 * 要查 `project_ids`（JSON 欄位，裡面可能存成字串），不是 validation rule
 * 表達得了的 —— 那一關在 `ProjectOwnerService::guardParticipation()`。
 */
class AssignOwnerRequest extends FormRequest
{
    /**
     * ⚠ 權限由 route 的 `can:project.assign_owner` 擋，
     * 「碰不碰得到這個專案」由 Controller 的 `canTouchProject()` 擋。
     *
     * @return bool
     */
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
             * ⚠ `nullable` 是功能需求不是偷懶：**清空負責人是合法操作** ——
             * 負責人離職而還沒決定接手的人時，擺一個已離職的名字比
             * 「未指派」更容易誤導。
             *
             * ⚠ `exists` 一定要帶 `deleted_at,NULL`：`user` 是軟刪除的，
             * 不加的話指派得了一個已經離職的人。
             */
            'owner_id' => 'nullable|integer|exists:user,id,deleted_at,NULL',
        ];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return [
            'owner_id.integer' => trans('project.owner_not_found'),
            'owner_id.exists'  => trans('project.owner_not_found'),
        ];
    }
}

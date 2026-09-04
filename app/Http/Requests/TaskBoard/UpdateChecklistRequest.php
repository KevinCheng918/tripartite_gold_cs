<?php

namespace App\Http\Requests\TaskBoard;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 任務描述中的勾選清單狀態更新
 *
 * 與 UpdateTaskRequest 分開的原因：勾選是高頻操作，
 * 走一般更新會每勾一次就寫一筆「描述變更」活動紀錄，把紀錄灌爆。
 */
class UpdateChecklistRequest extends FormRequest
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
            'description' => 'required|string',
        ];
    }

    public function messages()
    {
        return [
            'description.required' => trans('task_board.msg.description_required'),
        ];
    }
}

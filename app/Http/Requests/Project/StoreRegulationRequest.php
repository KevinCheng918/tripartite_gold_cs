<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectRegulation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 新增系統規章
 */
class StoreRegulationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            /*
             * ⚠ `exists:project,id` 只確認專案存在，**不保證這個人碰得到它** ——
             * 「有沒有參與那個專案」在 Controller 的 `canTouchProject()` 擋
             * （Request 這一層拿不到那份名單）。
             */
            'project_id' => 'required|integer|exists:project,id',
            'title'      => 'required|string|max:' . config('rules.REGULATION_TITLE_MAX'),
            'content'    => 'required|string|max:' . config('rules.REGULATION_CONTENT_MAX'),
            'status'     => 'nullable|integer|in:' . ProjectRegulation::STATUS_ACTIVE . ',' . ProjectRegulation::STATUS_DISABLED,
            'sort'       => 'nullable|integer|min:0|max:9999',
        ];
    }

    public function messages()
    {
        return [
            'project_id.required' => trans('project.regulation_project_required'),
            'project_id.exists'   => trans('project.regulation_project_required'),
            'title.required'      => trans('project.regulation_title_required'),
            'title.max'           => trans('project.regulation_title_too_long'),
            'content.required'    => trans('project.regulation_content_required'),
            'content.max'         => trans('project.regulation_content_too_long'),
        ];
    }
}

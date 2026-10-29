<?php

namespace App\Http\Requests\Project;

use App\Models\ProjectRegulation;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新系統規章
 *
 * ⚠ **沒有 `project_id`**，而且是刻意的。規章改掛到別的專案等於換了一份
 * 文件的歸屬，而小幫手是用「標題＋專案」回答的 —— 改錯會讓人拿到別個專案
 * 的規章。要搬就刪了重建（見 `ProjectRegulationService::update()`）。
 */
class UpdateRegulationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'title'   => 'required|string|max:' . config('rules.REGULATION_TITLE_MAX'),
            'content' => 'required|string|max:' . config('rules.REGULATION_CONTENT_MAX'),
            'status'  => 'nullable|integer|in:' . ProjectRegulation::STATUS_ACTIVE . ',' . ProjectRegulation::STATUS_DISABLED,
            'sort'    => 'nullable|integer|min:0|max:9999',
        ];
    }

    public function messages()
    {
        return [
            'title.required'   => trans('project.regulation_title_required'),
            'title.max'        => trans('project.regulation_title_too_long'),
            'content.required' => trans('project.regulation_content_required'),
            'content.max'      => trans('project.regulation_content_too_long'),
        ];
    }
}

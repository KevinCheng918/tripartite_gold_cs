<?php

namespace App\Http\Requests\ShiftAssignment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 課表要顯示的固定班區間
 *
 * ⚠ 起訖**都是必填**，跟 `ajax-assignment-list` 那支（兩個都可選）不同：
 * 這一支的結果是逐日算出來的，沒有日期範圍就等於要算到天荒地老。
 */
class ListFixedAssignmentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'date_from' => 'required|date_format:Y-m-d',
            'date_to'   => 'required|date_format:Y-m-d|after_or_equal:date_from',
        ];
    }

    public function messages()
    {
        // 沿用既有的 key，不為同一句話再開一組
        return [
            'date_from.required'     => trans('shift.msg.required'),
            'date_from.date_format'  => trans('shift.msg.invalid_date_format'),
            'date_to.required'       => trans('shift.msg.required'),
            'date_to.date_format'    => trans('shift.msg.invalid_date_format'),
            'date_to.after_or_equal' => trans('shift.msg.range_reversed'),
        ];
    }
}

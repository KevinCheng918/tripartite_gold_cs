<?php

namespace App\Http\Requests\Followup;

use App\Services\CustomerFollowupService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 待追蹤事項列表的篩選條件
 */
class ListFollowupRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'source' => ['nullable', Rule::in(array_values((array) config('constants.FOLLOWUP.SOURCE')))],

            /*
             * 狀態可以是某一個狀態、`all`（全部）或 `open`（只看未結案）。
             * **留白＝全部**（2026-10-12 改）—— 跟畫面上的預設一致。
             */
            'status' => [
                'nullable',
                Rule::in(array_merge(
                    array_values((array) config('constants.FOLLOWUP.STATUS')),
                    [CustomerFollowupService::STATUS_ALL, CustomerFollowupService::STATUS_OPEN]
                )),
            ],

            /*
             * 開單日期區間。兩個都可留白（＝不限期間），
             * 但有填就要是完整的一組 —— 只填一邊篩不出有意義的結果。
             */
            'date_from' => 'nullable|date_format:Y-m-d|required_with:date_to',
            'date_to'   => 'nullable|date_format:Y-m-d|required_with:date_from|after_or_equal:date_from',

            'group_id' => 'nullable|integer|exists:telegram_group,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function messages()
    {
        return [
            'date_from.date_format'  => trans('followup.msg.date_invalid'),
            'date_to.date_format'    => trans('followup.msg.date_invalid'),
            'date_from.required_with' => trans('followup.msg.range_required'),
            'date_to.required_with'   => trans('followup.msg.range_required'),
            'date_to.after_or_equal'  => trans('followup.msg.range_reversed'),
        ];
    }
}

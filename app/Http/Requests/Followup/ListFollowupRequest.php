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
             * 狀態可以是某一個狀態，或字串 all（看全部，含已結案）。
             * 留白＝預設只看還活著的那兩種，由 Service 決定。
             */
            'status' => [
                'nullable',
                Rule::in(array_merge(
                    array_values((array) config('constants.FOLLOWUP.STATUS')),
                    [CustomerFollowupService::STATUS_ALL]
                )),
            ],

            'group_id' => 'nullable|integer|exists:telegram_group,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }
}

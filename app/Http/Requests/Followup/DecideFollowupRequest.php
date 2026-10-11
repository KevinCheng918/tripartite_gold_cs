<?php

namespace App\Http\Requests\Followup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 後台手動追蹤／結案
 *
 * ⚠ `follow` 是必填而不是「有送就追蹤」：兩個動作的後果差很多
 * （一個會發訊息給客人，一個直接結案），不能靠欄位在不在來決定。
 */
class DecideFollowupRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'follow' => 'required|boolean',
        ];
    }

    public function messages()
    {
        return [
            'follow.required' => trans('followup.msg.decide_required'),
            'follow.boolean'  => trans('followup.msg.decide_required'),
        ];
    }
}

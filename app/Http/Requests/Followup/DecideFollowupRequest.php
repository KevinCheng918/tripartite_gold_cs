<?php

namespace App\Http\Requests\Followup;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 後台手動追蹤／繼續等／結案
 *
 * ⚠ `action` 是必填而不是「有送就追蹤」：三個動作的後果差很多
 * （追蹤會發訊息給客人、結案直接收掉、繼續等只是把鬧鐘按掉），
 * 不能靠欄位在不在來決定。
 *
 * ⚠ **2026-10-12 從 `follow`（布林）換成 `action`（字串）。** 加了第三個
 * 選項之後布林就不夠用了 —— 硬要用 `follow=null` 表示「繼續等」的話，
 * 那個 null 到底是「沒送」還是「繼續等」分不出來。
 * 值跟 Telegram 按鈕的 `callback_data` 共用同一組常數，兩個入口不會分岔。
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
            'action' => [
                'required',
                Rule::in(array_values((array) config('constants.FOLLOWUP.DECISION'))),
            ],
        ];
    }

    public function messages()
    {
        return [
            'action.required' => trans('followup.msg.decide_required'),
            'action.in'       => trans('followup.msg.decide_required'),
        ];
    }
}

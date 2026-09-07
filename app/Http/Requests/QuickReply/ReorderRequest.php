<?php

namespace App\Http\Requests\QuickReply;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 快速回覆拖曳重排驗證
 *
 * ids 是拖曳後由上到下的完整順序。
 */
class ReorderRequest extends FormRequest
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
            'ids'   => 'required|array|min:1',
            'ids.*' => 'required|integer',
        ];
    }

    public function messages()
    {
        return [
            'ids.required' => trans('quick_reply.msg.sort_failed'),
            'ids.array'    => trans('quick_reply.msg.sort_failed'),
        ];
    }
}

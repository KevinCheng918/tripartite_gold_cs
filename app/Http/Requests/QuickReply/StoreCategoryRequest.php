<?php

namespace App\Http\Requests\QuickReply;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 新增快速回覆類別驗證
 */
class StoreCategoryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'label'  => 'required|string|max:100',
            'status' => 'sometimes|integer|in:0,1',
        ];
    }

    public function messages()
    {
        return [
            'label.required' => trans('quick_reply.msg.label_required'),
            'label.max'      => trans('quick_reply.msg.label_max', ['value' => '100']),
        ];
    }
}

<?php

namespace App\Http\Requests\QuickReply;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 新增快速回覆問答驗證
 */
class StoreItemRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'category_id' => 'required|integer|exists:quick_reply_category,id',
            'label'       => 'required|string|max:200',
            'answer'      => 'required|string|max:4000',
            'status'      => 'sometimes|integer|in:0,1',
        ];
    }

    public function messages()
    {
        return [
            'category_id.required' => trans('quick_reply.msg.category_required'),
            'category_id.exists'   => trans('quick_reply.msg.category_not_found'),
            'label.required'       => trans('quick_reply.msg.label_required'),
            'label.max'            => trans('quick_reply.msg.label_max', ['value' => '200']),
            'answer.required'      => trans('quick_reply.msg.answer_required'),
            'answer.max'           => trans('quick_reply.msg.answer_max', ['value' => '4000']),
        ];
    }
}

<?php

namespace App\Http\Requests\QuickReply;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新快速回覆問答驗證
 */
class UpdateItemRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'category_id' => 'sometimes|integer|exists:quick_reply_category,id',
            'label'       => 'sometimes|string|max:200',
            'answer'      => 'sometimes|string|max:4000',
            'status'      => 'sometimes|integer|in:0,1',
        ];
    }

    public function messages()
    {
        return [
            'category_id.exists' => trans('quick_reply.msg.category_not_found'),
            'label.max'          => trans('quick_reply.msg.label_max', ['value' => '200']),
            'answer.max'         => trans('quick_reply.msg.answer_max', ['value' => '4000']),
        ];
    }
}

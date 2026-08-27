<?php

namespace App\Http\Requests\QuickReply;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新快速回覆類別驗證
 */
class UpdateCategoryRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'label'  => 'sometimes|string|max:100',
            'status' => 'sometimes|integer|in:0,1',
        ];
    }

    public function messages()
    {
        return [
            'label.max' => trans('quick_reply.msg.label_max', ['value' => '100']),
        ];
    }
}

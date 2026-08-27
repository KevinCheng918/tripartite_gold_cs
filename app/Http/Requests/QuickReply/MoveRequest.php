<?php

namespace App\Http\Requests\QuickReply;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 快速回覆排序驗證（類別與問答共用）
 */
class MoveRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'direction' => 'required|string|in:up,down',
        ];
    }

    public function messages()
    {
        return [
            'direction.required' => trans('quick_reply.msg.direction_required'),
            'direction.in'       => trans('quick_reply.msg.direction_invalid'),
        ];
    }

    /**
     * 是否為上移
     *
     * @return bool
     */
    public function isUp()
    {
        return $this->input('direction') === 'up';
    }
}

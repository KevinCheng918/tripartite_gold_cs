<?php

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新班別時段驗證（僅 Admin）
 */
class UpdateShiftRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'display_name' => 'sometimes|string|max:100',
            'start_time'   => 'sometimes|date_format:H:i',
            'end_time'     => 'sometimes|date_format:H:i',
            'reply_start_time' => 'sometimes|nullable|date_format:H:i',
            'reply_end_time'   => 'sometimes|nullable|date_format:H:i',
            'is_active'    => 'sometimes|boolean',
            'sort'         => 'sometimes|integer|min:0',
        ];
    }

    public function messages()
    {
        return [
            'display_name.max'      => trans('shift.msg.max_string', ['value' => '100']),
            'start_time.date_format' => trans('shift.msg.invalid_time_format'),
            'end_time.date_format'   => trans('shift.msg.invalid_time_format'),
        ];
    }
}

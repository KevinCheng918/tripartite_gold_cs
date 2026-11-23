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
            // 這一班幾點交接。留空＝沿用下一班回訊開始（見 config/rules.php）
            'handover_time'    => 'sometimes|' . config('rules.SHIFT_HANDOVER_TIME'),
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
            'handover_time.date_format' => trans('shift.msg.invalid_time_format'),
        ];
    }
}

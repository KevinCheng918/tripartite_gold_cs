<?php

namespace App\Http\Requests\ShiftAssignment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 排班驗證
 *
 * Admin：可傳入 user_id 指定客服排班。
 * 客服：user_id 由 Controller 從 Auth::id() 注入，不從前端傳入。
 */
class StoreAssignmentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'user_id'  => 'sometimes|integer|exists:user,id',
            'shift_id' => 'required|integer|exists:shifts,id',
            'date'     => 'required|date_format:Y-m-d',
        ];
    }

    public function messages()
    {
        return [
            'user_id.exists'    => trans('shift.msg.user_not_found'),
            'shift_id.required' => trans('shift.msg.required'),
            'shift_id.exists'   => trans('shift.msg.shift_not_found'),
            'date.required'     => trans('shift.msg.required'),
            'date.date_format'  => trans('shift.msg.invalid_date_format'),
        ];
    }
}

<?php

namespace App\Http\Requests\ShiftAssignment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 發起換班請求驗證
 */
class SwapRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'requester_assignment_id' => 'required|integer|exists:shift_assignments,id',
            'target_assignment_id'    => 'required|integer|exists:shift_assignments,id',
        ];
    }

    public function messages()
    {
        return [
            'requester_assignment_id.required' => trans('shift.msg.required'),
            'requester_assignment_id.exists'   => trans('shift.msg.assignment_not_found'),
            'target_assignment_id.required'    => trans('shift.msg.required'),
            'target_assignment_id.exists'      => trans('shift.msg.assignment_not_found'),
        ];
    }
}

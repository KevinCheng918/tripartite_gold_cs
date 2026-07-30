<?php

namespace App\Http\Requests\ShiftAssignment;

use App\Models\ShiftSwap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 回應換班請求驗證（同意或拒絕）
 */
class RespondSwapRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'status' => [
                'required',
                'integer',
                Rule::in([ShiftSwap::STATUS_APPROVED, ShiftSwap::STATUS_REJECTED]),
            ],
        ];
    }

    public function messages()
    {
        return [
            'status.required' => trans('shift.msg.required'),
            'status.in'       => trans('shift.msg.invalid_swap_status'),
        ];
    }
}

<?php

namespace App\Http\Requests\ShiftCover;

use App\Models\ShiftCover;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 回應代班請求驗證（代班人同意/拒絕 或 管理者核准/駁回）
 */
class RespondCoverRequest extends FormRequest
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
                Rule::in([ShiftCover::STATUS_APPROVED, ShiftCover::STATUS_REJECTED]),
            ],
        ];
    }

    public function messages()
    {
        return [
            'status.required' => trans('cover.msg.required'),
            'status.in'       => trans('cover.msg.invalid_status'),
        ];
    }
}

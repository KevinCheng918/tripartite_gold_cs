<?php

namespace App\Http\Requests\ShiftCover;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 發起代班申請驗證
 */
class StoreCoverRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'assignment_id' => 'required|integer|exists:shift_assignments,id',
            'cover_user_id' => 'required|integer|exists:user,id',
            'cover_start'   => 'required|date_format:H:i',
            'cover_end'     => 'required|date_format:H:i',
            'reason'        => 'nullable|string|max:255',
        ];
    }

    public function messages()
    {
        return [
            'assignment_id.required' => trans('cover.msg.required'),
            'assignment_id.exists'   => trans('cover.msg.assignment_not_found'),
            'cover_user_id.required' => trans('cover.msg.required'),
            'cover_user_id.exists'   => trans('cover.msg.user_not_found'),
            'cover_start.required'   => trans('cover.msg.required'),
            'cover_start.date_format' => trans('cover.msg.invalid_time_format'),
            'cover_end.required'     => trans('cover.msg.required'),
            'cover_end.date_format'  => trans('cover.msg.invalid_time_format'),
        ];
    }
}

<?php

namespace App\Http\Requests\LeaveRequest;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'user_id'     => 'nullable|integer|exists:user,id',
            'start_date'  => 'required|date',
            'end_date'    => 'nullable|date|after_or_equal:start_date',
            'is_full_day' => 'required|integer|in:0,1',
            'start_time'  => 'required_if:is_full_day,0|nullable',
            'end_time'    => 'required_if:is_full_day,0|nullable',
            'reason'      => 'nullable|string|max:500',
        ];
    }
}

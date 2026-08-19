<?php

namespace App\Http\Requests\LeaveRequest;

use Illuminate\Foundation\Http\FormRequest;

class RespondLeaveRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'status'      => 'required|integer|in:1,2',
            'review_note' => 'nullable|string|max:500',
        ];
    }
}

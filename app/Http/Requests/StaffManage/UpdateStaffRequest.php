<?php

namespace App\Http\Requests\StaffManage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'hired_at'    => 'nullable|date',
            'resigned_at' => 'nullable|date',
            'equipments' => 'nullable|array',
        ];
    }
}

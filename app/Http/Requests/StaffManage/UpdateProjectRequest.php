<?php

namespace App\Http\Requests\StaffManage;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name'        => 'required|string|max:100|unique:project,name,' . $this->route('project')->id,
            'description' => 'nullable|string|max:500',
            'status'      => 'sometimes|integer|in:0,1',
        ];
    }
}

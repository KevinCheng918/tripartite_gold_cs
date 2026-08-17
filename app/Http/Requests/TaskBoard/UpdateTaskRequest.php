<?php

namespace App\Http\Requests\TaskBoard;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTaskRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'project_id'  => 'sometimes|integer|exists:project,id',
            'station_id'  => 'nullable|integer|exists:station,id',
            'title'       => 'sometimes|string|max:200',
            'description' => 'nullable|string',
            'status'      => 'sometimes|integer|in:1,2,3,4',
            'priority'    => 'sometimes|integer|in:1,2,3,4',
            'assignee_id' => 'nullable|integer|exists:user,id',
            'due_date'    => 'nullable|date',
        ];
    }
}

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
            'status'      => 'sometimes|integer|in:1,2,3,4,5',
            'priority'    => 'sometimes|integer|in:1,2,3,4',
            'assignee_ids'   => 'nullable|array',
            'assignee_ids.*' => 'integer',
            'due_date'    => 'nullable|date',
            'images'      => 'nullable|array',
            'images.*'    => 'image|max:5120',
        ];
    }
}

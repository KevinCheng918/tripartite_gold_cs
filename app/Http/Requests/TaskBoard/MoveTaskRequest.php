<?php

namespace App\Http\Requests\TaskBoard;

use Illuminate\Foundation\Http\FormRequest;

class MoveTaskRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'status'     => 'required|integer|in:1,2,3,4',
            'sort_order' => 'required|integer|min:0',
        ];
    }
}

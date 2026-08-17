<?php

namespace App\Http\Requests\TaskBoard;

use Illuminate\Foundation\Http\FormRequest;

class ReorderTaskRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'orders'              => 'required|array',
            'orders.*.id'         => 'required|integer|exists:task,id',
            'orders.*.sort_order' => 'required|integer|min:0',
        ];
    }
}

<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;

class StoreRoleRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $rules = config('rules.role');

        $rules['name'][] = 'unique:roles,name';
        $rules['description'] = ['nullable', 'string', 'max:255'];
        $rules['is_active'] = ['nullable', 'boolean'];
        $rules['sort'] = ['nullable', 'integer'];

        return $rules;
    }
}

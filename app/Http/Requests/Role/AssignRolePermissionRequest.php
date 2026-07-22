<?php

namespace App\Http\Requests\Role;

use App\Services\PermissionMapService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignRolePermissionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $validKeywords = app(PermissionMapService::class)->getAllKeywords();

        return [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', Rule::in($validKeywords)],
        ];
    }
}

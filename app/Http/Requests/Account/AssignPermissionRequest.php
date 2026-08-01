<?php

namespace App\Http\Requests\Account;

use App\Services\PermissionMapService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 設定帳號權限驗證
 */
class AssignPermissionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $validKeywords = app(PermissionMapService::class)->getAllKeywords();

        return [
            'permissions'   => 'present|array',
            'permissions.*' => ['string', Rule::in($validKeywords)],
        ];
    }

    public function messages()
    {
        return [
            'permissions.present' => trans('account.msg.permissions_required'),
            'permissions.*.in'    => trans('account.msg.invalid_permission'),
        ];
    }
}

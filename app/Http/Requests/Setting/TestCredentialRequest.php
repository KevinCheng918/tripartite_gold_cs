<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 測試憑證：要測主要還是備援
 *
 * ⚠ 用白名單而不是布林：這個值決定要去讀哪一把金鑰，
 * 認不得的值靜悄悄落到主要那條，使用者按「測試備援」卻測到訂閱 token，
 * 得到的「正常」是假的。
 */
class TestCredentialRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'type' => 'required|string|' . config('rules.SETTING_CREDENTIAL_TYPE_IN'),
        ];
    }

    public function messages()
    {
        return [
            'type.required' => trans('setting.msg.test_type_invalid'),
            'type.in'       => trans('setting.msg.test_type_invalid'),
        ];
    }
}

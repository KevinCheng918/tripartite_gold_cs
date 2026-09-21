<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 更新備援 API 設定
 *
 * 備援會實際產生費用，所以每日上限是必填 —— 0 代表不限制，
 * 但那是要使用者自己明確選的，不會是沒填欄位的副作用。
 */
class UpdateFallbackRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'enabled'     => 'required|boolean',
            'api_key'     => 'nullable|string|max:500',
            'model'       => ['required', 'string', Rule::in(array_keys(config('auto_reply.models')))],
            'daily_limit' => 'required|integer|min:0|max:100000',
        ];
    }

    public function messages()
    {
        return [
            'api_key.max'         => trans('setting.msg.token_max'),
            'model.required'      => trans('setting.msg.model_required'),
            'model.in'            => trans('setting.msg.model_invalid'),
            'daily_limit.required' => trans('setting.msg.daily_limit_required'),
            'daily_limit.integer' => trans('setting.msg.daily_limit_invalid'),
            'daily_limit.min'     => trans('setting.msg.daily_limit_invalid'),
            'daily_limit.max'     => trans('setting.msg.daily_limit_invalid'),
        ];
    }
}

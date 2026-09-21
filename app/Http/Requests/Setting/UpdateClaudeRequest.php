<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 更新 Claude 主要設定（走訂閱）
 *
 * token 留空代表沿用原本的 —— 前端只拿得到遮罩，不會把明文送回來。
 */
class UpdateClaudeRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'token' => 'nullable|string|max:500',
            'model' => ['required', 'string', Rule::in(array_keys(config('auto_reply.models')))],
        ];
    }

    public function messages()
    {
        return [
            'token.max'     => trans('setting.msg.token_max'),
            'model.required' => trans('setting.msg.model_required'),
            'model.in'      => trans('setting.msg.model_invalid'),
        ];
    }
}

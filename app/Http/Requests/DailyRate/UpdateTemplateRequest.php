<?php

namespace App\Http\Requests\DailyRate;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 報價公版設定
 */
class UpdateTemplateRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'template' => 'required|string|max:' . config('rules.TELEGRAM_TEMPLATE_MAX'),
        ];
    }

    public function messages()
    {
        return [
            'template.required' => trans('daily_rate.msg.template_required'),
            'template.max'      => trans('daily_rate.msg.template_max', [
                'value' => config('rules.TELEGRAM_TEMPLATE_MAX'),
            ]),
        ];
    }
}

<?php

namespace App\Http\Requests\DailyRate;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 報價公版設定
 */
class UpdateTemplateRequest extends FormRequest
{
    /** @var int 公版字數上限。Telegram 單則訊息硬上限 4096，留餘裕給變數代換後變長 */
    private const TEMPLATE_MAX = 2000;

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'template' => 'required|string|max:' . self::TEMPLATE_MAX,
        ];
    }

    public function messages()
    {
        return [
            'template.required' => trans('daily_rate.msg.template_required'),
            'template.max'      => trans('daily_rate.msg.template_max', ['value' => self::TEMPLATE_MAX]),
        ];
    }
}

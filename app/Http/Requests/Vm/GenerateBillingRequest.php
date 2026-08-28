<?php

namespace App\Http\Requests\Vm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 產生 VM 月帳單驗證
 *
 * 只允許產生本月或之後的帳單，避免補開過去月份造成帳務對不起來。
 */
class GenerateBillingRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            'month'        => ['nullable', 'string', 'date_format:Y-m', $this->notPastMonth()],
            'force_update' => 'nullable|boolean',
        ];
    }

    public function messages()
    {
        return [
            'month.date_format' => trans('vm.msg.month_format_invalid'),
        ];
    }

    /**
     * 不得早於當月
     *
     * Y-m 格式的字串比較與時間先後一致（'2026-07' < '2026-08'），可直接比字串。
     *
     * @return \Closure
     */
    private function notPastMonth()
    {
        return function ($attribute, $value, $fail) {
            if (filled($value) && $value < now()->format('Y-m')) {
                $fail(trans('vm.msg.month_cannot_be_past'));
            }
        };
    }
}

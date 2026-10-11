<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 報表頁查詢條件
 *
 * ⚠ `type` 用白名單而不是自由字串：它會被拿去決定區間怎麼算，
 * 認不得的值會靜悄悄落到「日」那條 —— 使用者看到的是錯的期間卻不會有錯誤。
 *
 * ⚠ 打卡報表沒有「日」這個選項（需求方只要週與月），但驗證放行三種 ——
 * 擋在前端選單就好，後端為了一個不會出現的組合多寫一條規則不值得。
 * 真的收到 `daily` 也只是回傳那一天的統計，不會出事。
 */
class ShowReportRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'type' => 'required|string|' . config('rules.REPORT_PERIOD_TYPE_IN'),

            // 以哪一天往回推。不給就是今天（看上一期）
            'date' => 'nullable|date_format:Y-m-d',
        ];
    }

    public function messages()
    {
        return [
            'type.required'    => trans('report.msg.type_invalid'),
            'type.in'          => trans('report.msg.type_invalid'),
            'date.date_format' => trans('report.msg.date_invalid'),
        ];
    }
}

<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * 報表頁查詢條件：一段起訖日期
 *
 * ⚠ 沒有「日／週／月」這種期間類型。畫面上那排快捷鈕（今日／昨日／本週／
 * 上週／本月／上月）在前端就換算成起訖日期了，後端只需要知道要統計哪一段 ——
 * 比照補點紀錄的篩選。多一個 type 參數的話，「上週」會有兩套算法
 * （前端一套、後端一套），遲早會對不起來。
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
            'start' => 'required|date_format:Y-m-d',
            'end'   => 'required|date_format:Y-m-d|after_or_equal:start',
        ];
    }

    /**
     * 期間的天數上限
     *
     * ⚠ 沒有內建規則能比較「兩個欄位相差幾天」，所以擋在這裡。
     * 放行的話一次就會撈進幾年的資料跑迴圈，畫面卡死且查不出原因。
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $start = $this->input('start');
            $end = $this->input('end');

            /*
             * 上面的規則有任何一條沒過就不再多報一句 —— 一次讓使用者看一個問題。
             *
             * ⚠ `blank()` 那兩個判斷在目前的規則下走不到（兩個欄位都是
             * `required`，缺了會先被攔下）。留著是因為下面要 `Carbon::parse()`：
             * 規則哪天改成 nullable，少了它就是一個沒人預期的 parse 例外。
             */
            if (blank($start) || blank($end) || $validator->errors()->isNotEmpty()) {
                return;
            }

            $maxDays = (int) config('rules.REPORT_RANGE_MAX_DAYS');

            // +1 是因為起訖都算在內：1 日到 1 日是一天，不是零天
            if (Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1 <= $maxDays) {
                return;
            }

            $validator->errors()->add('end', trans('report.msg.range_too_long', ['days' => $maxDays]));
        });
    }

    public function messages()
    {
        return [
            'start.required'     => trans('report.msg.range_required'),
            'start.date_format'  => trans('report.msg.date_invalid'),
            'end.required'       => trans('report.msg.range_required'),
            'end.date_format'    => trans('report.msg.date_invalid'),
            'end.after_or_equal' => trans('report.msg.range_reversed'),
        ];
    }
}

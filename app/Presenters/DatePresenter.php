<?php

namespace App\Presenters;

use Carbon\Carbon;

/**
 * 日期格式化 Presenter
 *
 * 出勤、排班這類看班表的頁面，只有日期很難一眼看出是平日還是假日，
 * 因此統一在日期後面補上星期。
 *
 * 前端的 JS 版本在 `public/js/common.js` 的 `window.withWeekday()`，
 * 兩邊的輸出格式必須一致。
 */
class DatePresenter
{
    /** @var array 星期簡稱，索引對應 Carbon 的 dayOfWeek（0=日 … 6=六） */
    private const WEEKDAYS = ['日', '一', '二', '三', '四', '五', '六'];

    /**
     * 在日期後面加上星期，例如 2026-09-08 → 2026-09-08 (二)
     *
     * @param \Carbon\Carbon|string|null $date
     * @param string                     $format 日期本身的格式
     * @return string 無法解析時回傳空字串
     */
    public static function withWeekday($date, $format = 'Y-m-d')
    {
        if (blank($date)) {
            return '';
        }

        $carbon = $date instanceof Carbon ? $date : Carbon::parse($date);
        $weekday = self::WEEKDAYS[$carbon->dayOfWeek];

        return "{$carbon->format($format)} ({$weekday})";
    }
}

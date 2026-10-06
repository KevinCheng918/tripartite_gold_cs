<?php

namespace App\Services\Notify;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * 通知文案的共用排版
 *
 * 三支通知 service 原本各抄一份一模一樣的 `formatDate()`，兩支各抄一份
 * `shorten()` —— 抽出來共用而不是各寫一份（比照 `OpeningSanitizer`）。
 *
 * 用它的地方：
 *   - `ShiftNoticeService`    — 班表通知的日期
 *   - `TicketHandoverService` — 待接手清單的日期、題目截短、已等多久
 *   - `RemindReportService`   — 提醒統計的日期與題目截短
 *
 * ⚠ 日期格式散成三份的問題不只是重複：同一批訊息（7:00、7:30、8:30 連著發）
 * 的日期寫法不一致，看起來就像不同系統發的。
 */
class NoticeText
{
    /** @var array 星期的中文，index 對 Carbon 的 dayOfWeek（0=日） */
    private const WEEKDAYS = ['日', '一', '二', '三', '四', '五', '六'];

    /**
     * `2026-10-06` → `10/6（週二）`
     *
     * @param string $date
     * @return string
     */
    public function date($date)
    {
        $carbon = Carbon::parse($date);

        return $carbon->format('n/j') . '（週' . Arr::get(self::WEEKDAYS, (int) $carbon->dayOfWeek, '') . '）';
    }

    /**
     * 截短並補省略號
     *
     * 連續空白先壓成一格 —— 客人貼的 log 常帶換行，原樣塞進 Telegram
     * 會把一行變成一整段。
     *
     * @param string $text
     * @param int    $chars 0 或負數代表不截
     * @return string
     */
    public function shorten($text, $chars)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        if ($chars < 1 || mb_strlen($text) <= $chars) {
            return $text;
        }

        return mb_substr($text, 0, $chars) . '…';
    }

    /**
     * 等待時間（天／小時／分鐘，只取最大的兩個單位）
     *
     * @param mixed $since    起算時間
     * @param array $template WAITED_DAYS / WAITED_HOURS / WAITED_MINS 三個樣板
     * @return string
     */
    public function waited($since, array $template)
    {
        if (blank($since)) {
            return '-';
        }

        $minutes = (int) now()->diffInMinutes($since);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);

        if ($days > 0) {
            return strtr((string) Arr::get($template, 'WAITED_DAYS'), [
                '{days}'  => $days,
                '{hours}' => $hours,
            ]);
        }

        if ($hours > 0) {
            return strtr((string) Arr::get($template, 'WAITED_HOURS'), [
                '{hours}'   => $hours,
                '{minutes}' => $minutes % 60,
            ]);
        }

        return strtr((string) Arr::get($template, 'WAITED_MINS'), ['{minutes}' => $minutes]);
    }
}

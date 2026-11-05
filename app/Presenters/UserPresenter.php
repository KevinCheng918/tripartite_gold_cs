<?php

namespace App\Presenters;

use Illuminate\Support\Carbon;

/**
 * 使用者 Presenter
 *
 * 提供 level / status 等選項的 HTML 產生，避免 Blade 硬編碼。
 */
class UserPresenter
{
    /** @var array level → 語系 key 對應 */
    private static $levelLangMap = [
        0 => 'account.level_admin',
        1 => 'account.level_boss',
        2 => 'account.level_leader',
        3 => 'account.level_engineer',
        4 => 'account.level_cs',
    ];

    /**
     * 產生 level 下拉選項 HTML
     *
     * @param array $excludeLevels 排除的 level（例如 [0] 排除管理者）
     * @return string
     */
    public static function levelOptions($excludeLevels = [])
    {
        $html = '';
        foreach (config('constants.USER.LEVEL') as $key => $value) {
            if (in_array($value, $excludeLevels)) {
                continue;
            }
            $label = trans(self::$levelLangMap[$value] ?? "account.level_{$key}");
            $html .= '<option value="' . $value . '">' . $label . '</option>';
        }

        return $html;
    }

    /** @var array level → badge 顏色 */
    private static $levelColorMap = [
        0 => 'bg-danger',
        1 => 'bg-dark',
        2 => 'bg-primary',
        3 => 'bg-info',
        4 => 'bg-secondary',
    ];

    /**
     * 取得 level 顯示名稱
     *
     * @param int $level
     * @return string
     */
    public static function levelName($level)
    {
        return trans(self::$levelLangMap[$level] ?? 'account.level_cs');
    }

    /**
     * 取得 level badge HTML
     *
     * @param int $level
     * @return string
     */
    public static function levelBadge($level)
    {
        $name = self::levelName($level);
        $color = self::$levelColorMap[$level] ?? 'bg-secondary';

        return '<span class="badge ' . $color . '">' . $name . '</span>';
    }

    /**
     * 年資講成「N 年 N 個月 N 天」
     *
     * ⚠⚠ **不要用 `$hired->diff(now())` 的 `->y / ->m / ->d`。**
     *
     * PHP 的 `DateTime::diff()` 在 `Asia/Taipei` 這種有歷史時區轉換的時區下，
     * **年月日的拆解會跳號** —— 總天數是對的，拆出來的那三個數字不是。
     *
     * 2026-11-05 需求方回報：到職日差一天，年資卻差兩天。實測
     * （基準 2026-11-05）：
     *
     * | 到職日 | Asia/Taipei | UTC |
     * |---|---|---|
     * | 2025-09-30 | 1 年 1 月 **6** 天 | 1 年 1 月 6 天 |
     * | 2025-10-01 | 1 年 1 月 **5** 天 | 1 年 1 月 **4** 天 |
     * | 2025-10-02 | 1 年 1 月 **3** 天 | 1 年 1 月 3 天 |
     *
     * 台北那一欄的 4 被跳過了，所以 10-01 與 10-02 相差兩天。
     * 總天數兩邊都是 401／400／399（那個是對的）。
     *
     * 改成**自己逐段推進**：先加滿整年、再加滿整月、剩下的才是天數。
     * 每一步都是明確的日期運算，不經過 `diff()` 的拆解。
     *
     * ⚠ `addYearsNoOverflow` / `addMonthsNoOverflow` 不能換成一般版本：
     * 1/31 加一個月在一般版本會溢位成 3/2或3/3。
     *
     * ⚠ `diffInYears` / `diffInDays` **不受這個問題影響**（它們是計數不是
     * 拆解），所以 `LeaveQuotaService` 的年資級距是對的 —— 不要連那裡
     * 一起「修」。
     *
     * @param mixed $hiredAt 到職日（null ＝ 沒填）
     * @return string|null null ＝ 沒填到職日
     */
    public static function tenureText($hiredAt)
    {
        if (blank($hiredAt)) {
            return null;
        }

        $hired = Carbon::parse($hiredAt)->startOfDay();
        $today = now()->startOfDay();

        if ($hired->greaterThan($today)) {
            return trans('account.tenure_not_started');
        }

        $years = (int) $hired->diffInYears($today);
        $afterYears = $hired->copy()->addYearsNoOverflow($years);

        $months = (int) $afterYears->diffInMonths($today);
        $afterMonths = $afterYears->copy()->addMonthsNoOverflow($months);

        $days = (int) $afterMonths->diffInDays($today);

        $parts = [];

        if ($years > 0) {
            $parts[] = trans('account.tenure_years', ['value' => $years]);
        }

        if ($months > 0) {
            $parts[] = trans('account.tenure_months', ['value' => $months]);
        }

        if ($days > 0) {
            $parts[] = trans('account.tenure_days', ['value' => $days]);
        }

        return filled($parts) ? implode(' ', $parts) : trans('account.tenure_today');
    }
}

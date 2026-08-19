<?php

namespace App\Presenters;

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
}

<?php

namespace App\Presenters;

use Illuminate\Support\Arr;

/**
 * User-Agent 解析：作業系統、瀏覽器、裝置類型
 *
 * 需求方 2026-11-05：登入通知要寫出「作業系統、瀏覽器、裝置」。
 *
 * ⚠⚠ **這是全專案唯一一份 UA 解析。**
 * 原本只有一份寫在 `resources/views/admin/accounts/index.blade.php` 的 JS
 * （`parseUA()`），只跑在瀏覽器端 —— 而登入通知是在伺服器端發的。
 * 那一份已經刪掉，改由 `LoginLogResource` 把解析結果一起回前端，
 * **所以畫面與 Telegram 通知看到的是同一份解析**。
 *
 * 不要為了方便又在別處寫第二份：這個專案已經為「兩份各自維護」付過代價
 * （`StaffResource` 與 `AccountResource` 各一份 `calcTenure()`，兩份同一個 bug）。
 *
 * ⚠ **沒有裝 UA 解析套件**（`composer.json` 裡沒有 `jenssegers/agent` 之類），
 * 也刻意不為這個需求裝 —— 規則就這幾條，搬一份正規表達式比多一個依賴便宜。
 */
class UserAgentPresenter
{
    /** 解析不出來時統一用這個，不要空字串（畫面會變成空白格） */
    const UNKNOWN = '-';

    /**
     * 作業系統規則，**順序有意義**
     *
     * ⚠ iPad 要排在 Mac 之前：iPadOS 的 UA 含 `Mac OS X`
     * （Safari 的「requestDesktopSite」行為），先比 Mac 會全部判成 macOS。
     * 同理 Android 要排在 Linux 之前 —— Android 的 UA 含 `Linux`。
     *
     * @var array<int, array{pattern: string, name: string, versions?: array<string, string>}>
     */
    private static $osRules = [
        [
            'pattern' => '/iPad;.*?OS ([\d_]+)/',
            'name'    => 'iPadOS',
        ],
        [
            'pattern' => '/iPhone OS ([\d_]+)/',
            'name'    => 'iOS',
        ],
        [
            'pattern' => '/Android ([\d.]+)/',
            'name'    => 'Android',
        ],
        [
            /*
             * ⚠ **Windows 11 的 UA 回報的也是 `Windows NT 10.0`** —— 微軟沒有
             * 為 11 改這個號碼，所以這裡分不出 10 與 11，一律顯示「Windows 10」。
             * 這是 UA 本身的限制，不是對照表漏了。
             */
            'pattern'  => '/Windows NT ([\d.]+)/',
            'name'     => 'Windows',
            'versions' => [
                '10.0' => '10',
                '6.3'  => '8.1',
                '6.2'  => '8',
                '6.1'  => '7',
            ],
        ],
        [
            'pattern' => '/Mac OS X ([\d_.]+)/',
            'name'    => 'macOS',
        ],
        [
            // 沒有版本號可撈，純粹認出來
            'pattern' => '/Linux/i',
            'name'    => 'Linux',
        ],
    ];

    /**
     * 瀏覽器規則，**順序有意義**
     *
     * ⚠ Edge 與 Opera 一定要排在 Chrome 之前：兩者都是 Chromium 核心，
     * UA 裡同時含 `Chrome/`，先比 Chrome 會把它們全判成 Chrome。
     * Safari 要排最後 —— 幾乎每個瀏覽器的 UA 都含 `Safari`。
     *
     * ⚠ `major_only`：瀏覽器**只留主版號**。Chrome 回報的是四段
     * （`131.0.6778.86`），四段寫進 Telegram 通知又長又沒人看 ——
     * 沒有人會從 patch 號判斷「這是不是我」。
     * 完整的 User-Agent 原字串仍然留在 `login_log.device`，查問題看得到。
     *
     * ⚠ 作業系統**不套這個**：macOS 的 `10.15` 與 `11` 是兩件事，
     * 砍成主版號會把 `10.15.7` 變成「macOS 10」。
     *
     * @var array<int, array{pattern: string, name: string, major_only: bool}>
     */
    private static $browserRules = [
        ['pattern' => '/Edg(?:e|A|iOS)?\/([\d.]+)/', 'name' => 'Edge',    'major_only' => true],
        ['pattern' => '/OPR\/([\d.]+)/',             'name' => 'Opera',   'major_only' => true],
        ['pattern' => '/CriOS\/([\d.]+)/',           'name' => 'Chrome',  'major_only' => true],
        ['pattern' => '/Firefox\/([\d.]+)/',         'name' => 'Firefox', 'major_only' => true],
        ['pattern' => '/Chrome\/([\d.]+)/',          'name' => 'Chrome',  'major_only' => true],
        ['pattern' => '/Version\/([\d.]+).*Safari/', 'name' => 'Safari',  'major_only' => true],
    ];

    /**
     * 把 User-Agent 拆成作業系統、瀏覽器、裝置
     *
     * @param string|null $userAgent
     * @return array{os: string, browser: string, device: string}
     */
    public static function parse($userAgent)
    {
        if (blank($userAgent)) {
            return [
                'os'      => self::UNKNOWN,
                'browser' => self::UNKNOWN,
                'device'  => self::UNKNOWN,
            ];
        }

        $ua = (string) $userAgent;

        return [
            'os'      => self::matchRules(self::$osRules, $ua),
            'browser' => self::matchRules(self::$browserRules, $ua),
            'device'  => self::device($ua),
        ];
    }

    /**
     * 裝置類型：桌機／手機／平板
     *
     * 需求方 2026-11-05 裁示：這一欄要顯示桌機／手機／平板。
     *
     * ⚠ **原本那份 JS 顯示的是排版引擎**（WebKit／Gecko／Trident）——
     * 使用者看到「WebKit」不知道那是什麼，對「這是不是我的裝置」毫無幫助。
     *
     * ⚠ 判斷順序：平板 → 手機 → 桌機。
     * Android 平板的 UA 含 `Android` 但**不含** `Mobile`，而手機兩者都有 ——
     * 所以不能只看 `Android`。
     *
     * @param string $ua
     * @return string
     */
    private static function device($ua)
    {
        if (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua) === 1) {
            return (string) trans('login_log.device_tablet');
        }

        if (preg_match('/Mobile|iPhone|iPod|Windows Phone/i', $ua) === 1) {
            return (string) trans('login_log.device_mobile');
        }

        return (string) trans('login_log.device_desktop');
    }

    /**
     * 跑一組規則，回「名稱 版本」
     *
     * @param array<int, array{pattern: string, name: string, versions?: array<string, string>, major_only?: bool}> $rules
     * @param string                                                                                                $ua
     * @return string
     */
    private static function matchRules(array $rules, $ua)
    {
        foreach ($rules as $rule) {
            $matches = [];

            if (preg_match(Arr::get($rule, 'pattern'), $ua, $matches) !== 1) {
                continue;
            }

            $name = (string) Arr::get($rule, 'name');
            // iOS／macOS 的版本是 `10_15_7` 這種底線格式
            $raw = str_replace('_', '.', (string) Arr::get($matches, 1));

            if (blank($raw)) {
                return $name;
            }

            if (Arr::get($rule, 'major_only') === true) {
                $raw = (string) Arr::first(explode('.', $raw));
            }

            $version = Arr::get((array) Arr::get($rule, 'versions', []), $raw, $raw);

            return "{$name} {$version}";
        }

        return self::UNKNOWN;
    }

    /**
     * 「同一台裝置」的指紋，用來判斷登入來源有沒有見過
     *
     * ⚠⚠ **不能直接比對 User-Agent 原字串。** Chrome 每次小版本更新 UA 就變了
     * （`Chrome/131.0.6778.86` → `Chrome/131.0.6778.140`），拿原字串比的話
     * 每個人每隔幾天就會收到一則「新裝置登入」—— 通知一吵就會被忽略，
     * 而被忽略的安全通知等於沒有。
     *
     * 所以指紋**只取名稱、不含版本號**：`Windows|Chrome|桌機`。
     * 換瀏覽器、換作業系統、從桌機換手機才算新裝置。
     *
     * @param string|null $userAgent
     * @return string
     */
    public static function signature($userAgent)
    {
        $parsed = self::parse($userAgent);

        $parts = [];
        foreach (['os', 'browser', 'device'] as $field) {
            // 版本號在空格後面，取第一段就是名稱
            $value = (string) Arr::get($parsed, $field);
            $parts[] = Arr::first(explode(' ', $value));
        }

        return implode('|', $parts);
    }
}

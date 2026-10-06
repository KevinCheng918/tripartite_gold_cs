<?php

namespace App\Services;

use App\Repositories\AppSettingRepository;
use Illuminate\Support\Facades\Cache;

/**
 * 系統層級設定服務
 *
 * 讀寫入口。後台有兩頁在維護它：「AI 引擎」（Claude 憑證、備援 API）
 * 與「通訊管理 → 通知設定」（支援群組、話題分流、通知收件人）。
 *
 * 敏感值（Claude token、API key）以 Crypt::encrypt 加密存放，明文只在這一層還原，
 * 對外一律只給遮罩。
 *
 * 設定極少變動但每則訊息都會讀，所以整份快取起來，寫入時主動清掉。
 */
class AppSettingService
{
    /** 快取鍵 */
    private const CACHE_KEY = 'app_setting.all';

    /** 快取秒數。設定改動時會主動清快取，這個 TTL 只是保險 */
    private const CACHE_SECONDS = 600;

    // Claude（主要，走訂閱）
    const KEY_CLAUDE_TOKEN       = 'auto_reply.claude_token';
    const KEY_CLAUDE_MODEL       = 'auto_reply.claude_model';
    const KEY_CLAUDE_VERIFIED_AT = 'auto_reply.claude_verified_at';

    // Claude（備援，走 API，會實際產生費用）
    const KEY_FALLBACK_ENABLED     = 'auto_reply.fallback_enabled';
    const KEY_FALLBACK_API_KEY     = 'auto_reply.fallback_api_key';
    const KEY_FALLBACK_MODEL       = 'auto_reply.fallback_model';
    const KEY_FALLBACK_DAILY_LIMIT = 'auto_reply.fallback_daily_limit';

    /*
     * 今日班表的完整版要私訊給誰。
     *
     * ⚠ **可以指定多個人**，存的是逗號串接的 id（`3,7,12`），用 `getIntList()` 讀。
     * 常數名沿用單數的 `manager_user_id` —— 換掉儲存鍵會讓既有設定值歸零。
     *
     * 放 app_setting 而不是 config：換人是常態，不該為了換個收件人重新部署。
     */
    const KEY_SHIFT_NOTICE_MANAGER = 'shift_notice.manager_user_id';

    // 內部支援群組
    const KEY_SUPPORT_CHAT_ID   = 'auto_reply.support_chat_id';
    const KEY_SUPPORT_SYSTEM_ID = 'auto_reply.support_system_id';

    /*
     * 內部支援群組的話題分流（Telegram Topics）。
     *
     * 存 JSON：`[{"name":"排程通知","thread_id":42,"types":["ticket_handover",…]}, …]`
     *
     * ⚠ 這裡用 JSON 而不是像收件人那樣的逗號串接 —— 這是**有結構的清單**
     * （每筆有名稱、id、一組勾選的類型），不是單純一串整數。
     */
    const KEY_SUPPORT_TOPICS = 'auto_reply.support_topics';

    /*
     * 求助單超時提醒。
     *
     * FIRST    = 開單後多久送第一次提醒
     * INTERVAL = 之後每隔多久再提醒一次（會一直催到單被處理）
     * MAX      = 最多催幾次，到上限就停並發一則收尾（深夜沒人值班時的煞車）
     *
     * ⚠ `KEY_REMIND_INTERVAL_MINUTES` 存的字串**刻意沿用舊的
     * `remind_second_minutes`**：2026-10-06 之前它的意思是「第二次提醒要再等
     * 多久」，改成「之後每隔」之後語意變了，但換掉字串會讓既有設定值歸零、
     * 悄悄退回預設值。常數名稱表達新語意，儲存鍵維持穩定。
     */
    const KEY_REMIND_FIRST_MINUTES    = 'auto_reply.remind_first_minutes';
    const KEY_REMIND_INTERVAL_MINUTES = 'auto_reply.remind_second_minutes';
    const KEY_REMIND_MAX_COUNT        = 'auto_reply.remind_max_count';

    /*
     * 每日提醒統計要私訊給誰。同樣**可以指定多個人**（逗號串接）。
     *
     * 跟 KEY_SHIFT_NOTICE_MANAGER 分開（需求方 2026-10-06 指定）——
     * 班表給排班的人看、超時統計給管績效的人看，不一定是同一批。
     */
    const KEY_REMIND_REPORT_MANAGER = 'auto_reply.remind_report_user_id';

    // 每日匯率報價（匯率頁維護）
    const KEY_DAILY_RATE_TEMPLATE = 'daily_rate.ask_template';

    // 站台餘點告警（繳款設定頁維護）
    const KEY_CREDIT_ALERT_TEMPLATE      = 'station_credit.alert_template';
    const KEY_CREDIT_ALERT_THRESHOLD     = 'station_credit.threshold';

    /*
     * 對客話術（tpl_*）2026-09-30 隨著「對客話術」頁一起移除。
     *
     * 開頭由模型的承接句負責、答案用題庫原文，中間那層外殼反而會跟承接句
     * 重複問候。剩下的兩段（轉人工的 fallback、同仁作答的外殼）搬到
     * config/auto_reply.php 的 templates。
     *
     * 既有資料留在 app_setting 沒有影響，不為了這個跑一支 migration ——
     * 同 tpl_clarify_*（更早移除的反問編號選項）的處理方式。
     */

    /** @var array 需要加密存放的設定 */
    private const SECRET_KEYS = [
        self::KEY_CLAUDE_TOKEN,
        self::KEY_FALLBACK_API_KEY,
    ];

    private $appSettingRepository;

    public function __construct(AppSettingRepository $appSettingRepository)
    {
        $this->appSettingRepository = $appSettingRepository;
    }

    /**
     * 讀取設定值（敏感值已解密）
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function get($key, $default = null)
    {
        $settings = $this->all();

        if (!isset($settings[$key])) {
            return $default;
        }

        $value = $settings[$key]->plain_value;

        return filled($value) ? $value : $default;
    }

    /**
     * 讀取布林設定
     *
     * @param string $key
     * @param bool   $default
     * @return bool
     */
    public function getBool($key, $default = false)
    {
        $value = $this->get($key);

        if (!filled($value)) {
            return $default;
        }

        return in_array($value, ['1', 'true', 1, true], true);
    }

    /**
     * 讀取整數設定
     *
     * @param string $key
     * @param int    $default
     * @return int
     */
    public function getInt($key, $default = 0)
    {
        $value = $this->get($key);

        return filled($value) ? (int) $value : $default;
    }

    /**
     * 讀取「一串 id」設定
     *
     * 通知收件人這種「可以指定多個人」的設定用這支。存的是逗號串接的字串
     * （`3,7,12`），不是 JSON —— 內容就只是一串整數，JSON 只會多一層轉義。
     *
     * 回傳一定是 array：空字串、null、只有逗號都回空陣列，呼叫端不必再判斷。
     *
     * @param string $key
     * @return array<int, int>
     */
    public function getIntList($key)
    {
        $value = (string) $this->get($key);

        if (blank($value)) {
            return [];
        }

        $ids = [];

        foreach (explode(',', $value) as $part) {
            $id = (int) trim($part);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 讀取 JSON 設定
     *
     * ⚠ **解析失敗要回預設值而不是讓它炸開**：設定是人填的，手動改壞過一次
     * 就會讓每一次讀取都丟例外 —— 而這些設定在排程裡被讀，炸開等於整輪排程停擺。
     *
     * @param string $key
     * @param array  $default
     * @return array
     */
    public function getJson($key, $default = [])
    {
        $value = $this->get($key);

        if (blank($value)) {
            return $default;
        }

        $decoded = json_decode($value, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            \Log::warning('設定值不是合法的 JSON，已退回預設值', ['key' => $key]);

            return $default;
        }

        return $decoded;
    }

    /**
     * 把陣列寫成 JSON 設定值
     *
     * 空陣列存 null 而不是 `[]` —— 理由同 `idListValue()`。
     *
     * @param array $value
     * @return string|null
     */
    public function jsonValue($value)
    {
        $value = (array) $value;

        if (blank($value)) {
            return null;
        }

        // UNESCAPED_UNICODE：話題名稱是中文，不轉義才看得懂資料庫裡存了什麼
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 把一串 id 寫成設定值
     *
     * 全部清空時存 null 而不是空字串 —— 資料庫裡留個 `''`
     * 跟「從來沒設定過」看起來一樣，但多一列查不出用途的紀錄。
     *
     * @param array $ids
     * @return string|null
     */
    public function idListValue($ids)
    {
        $clean = [];

        foreach ((array) $ids as $id) {
            $id = (int) $id;

            if ($id > 0) {
                $clean[] = $id;
            }
        }

        $clean = array_values(array_unique($clean));

        return filled($clean) ? implode(',', $clean) : null;
    }

    /**
     * 讀取數值設定
     *
     * 餘點門檻這種會跟小數比較的值用這支，不要用 getInt ——
     * 點數本身是 decimal(15,2)，門檻被截成整數會在邊界上判斷錯。
     *
     * @param string $key
     * @param float  $default
     * @return float
     */
    public function getFloat($key, $default = 0.0)
    {
        $value = $this->get($key);

        return filled($value) ? (float) $value : $default;
    }

    /**
     * 寫入設定
     *
     * @param string      $key
     * @param string|null $value  明文；敏感值在這裡加密
     * @param int|null    $userId 操作者
     * @return void
     */
    public function put($key, $value, $userId = null)
    {
        $isSecret = $this->isSecret($key);

        if ($isSecret && filled($value)) {
            $value = \Crypt::encrypt($value);
        }

        $this->appSettingRepository->put($key, $value, $isSecret, $userId);
        $this->flush();
    }

    /**
     * 批次寫入設定
     *
     * @param array    $values key => value
     * @param int|null $userId
     * @return void
     */
    public function putMany($values, $userId = null)
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value, $userId);
        }
    }

    /**
     * 該 key 是否為敏感值
     *
     * @param string $key
     * @return bool
     */
    public function isSecret($key)
    {
        return in_array($key, self::SECRET_KEYS, true);
    }

    /**
     * 取得遮罩後的值（供設定頁顯示，明文不外流）
     *
     * @param string $key
     * @return string|null
     */
    public function masked($key)
    {
        $settings = $this->all();

        return isset($settings[$key]) ? $settings[$key]->masked_value : null;
    }

    /**
     * 是否已設定（用於判斷自動回覆能不能開）
     *
     * @param string $key
     * @return bool
     */
    public function has($key)
    {
        return filled($this->get($key));
    }

    /**
     * 清掉設定快取
     *
     * @return void
     */
    public function flush()
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * 全部設定（以 key 為索引），整份快取
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function all()
    {
        $repository = $this->appSettingRepository;

        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, function () use ($repository) {
            return $repository->getAll();
        });
    }
}

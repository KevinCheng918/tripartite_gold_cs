<?php

namespace App\Services;

use App\Repositories\AppSettingRepository;
use Illuminate\Support\Facades\Cache;

/**
 * 全域設定服務
 *
 * 後台「全域設定」頁的讀寫入口。敏感值（Claude token、API key）以 Crypt::encrypt
 * 加密存放，明文只在這一層還原，對外一律只給遮罩。
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

    // 內部支援群組
    /*
     * 今日班表要私訊給誰（user.id）。
     *
     * 放 app_setting 而不是 config：換人是常態，不該為了換個收件人重新部署。
     */
    const KEY_SHIFT_NOTICE_MANAGER = 'shift_notice.manager_user_id';

    const KEY_SUPPORT_CHAT_ID       = 'auto_reply.support_chat_id';
    const KEY_SUPPORT_SYSTEM_ID     = 'auto_reply.support_system_id';
    const KEY_REMIND_FIRST_MINUTES  = 'auto_reply.remind_first_minutes';
    const KEY_REMIND_SECOND_MINUTES = 'auto_reply.remind_second_minutes';

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

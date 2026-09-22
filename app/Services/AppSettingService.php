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
    const KEY_SUPPORT_CHAT_ID       = 'auto_reply.support_chat_id';
    const KEY_SUPPORT_SYSTEM_ID     = 'auto_reply.support_system_id';
    const KEY_REMIND_FIRST_MINUTES  = 'auto_reply.remind_first_minutes';
    const KEY_REMIND_SECOND_MINUTES = 'auto_reply.remind_second_minutes';

    // 對客話術模板（完整版 / 精簡版）
    const KEY_TPL_ANSWER_FULL   = 'auto_reply.tpl_answer_full';
    const KEY_TPL_ANSWER_SHORT  = 'auto_reply.tpl_answer_short';
    // 反問編號選項已移除，tpl_clarify_* 不再讀取。
    // 既有資料留在 app_setting 沒有影響，不為了這個跑一支 migration
    const KEY_TPL_WAIT_FULL     = 'auto_reply.tpl_wait_full';
    const KEY_TPL_WAIT_SHORT    = 'auto_reply.tpl_wait_short';
    const KEY_TPL_SUPPORT_FULL  = 'auto_reply.tpl_support_full';
    const KEY_TPL_SUPPORT_SHORT = 'auto_reply.tpl_support_short';

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

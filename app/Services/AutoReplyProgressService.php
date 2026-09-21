<?php

namespace App\Services;

use App\Events\AutoReplyProgress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 自動回覆「進行中」狀態
 *
 * Claude Code CLI 一次要跑數秒到數十秒，這段期間後台沒有任何跡象，
 * 客服會不知道系統正在處理而自己也回一次，客人就收到兩份答案。
 *
 * 狀態只存 Cache 不落 DB —— 壽命只有幾十秒，
 * 寫進 telegram_group 等於為了暫時狀態去寫一張每則訊息都要讀的表，
 * 而且 worker 掛掉時欄位會永遠留在「進行中」沒人收得掉。
 */
class AutoReplyProgressService
{
    /** @var string config 讀不到時的備用前綴，理由見 cacheKey() */
    private const FALLBACK_PREFIX = 'auto_reply.running.';

    /** @var int config 讀不到時的備用秒數，理由見 cacheSeconds() */
    private const FALLBACK_SECONDS = 180;

    /**
     * 標記開始回覆並通知前端
     *
     * @param int $groupId
     * @return void
     */
    public function start($groupId)
    {
        Cache::put($this->cacheKey($groupId), true, $this->cacheSeconds());

        $this->broadcast($groupId, true);
    }

    /**
     * 標記回覆結束並通知前端
     *
     * 會被呼叫兩次（Job 的 finally 與 failed），重複呼叫是安全的。
     *
     * @param int $groupId
     * @return void
     */
    public function finish($groupId)
    {
        Cache::forget($this->cacheKey($groupId));

        $this->broadcast($groupId, false);
    }

    /**
     * 從一批群組裡挑出正在回覆中的
     *
     * 用 Cache::many 一次取回，不要在迴圈裡一個一個查。
     *
     * @param array $groupIds
     * @return array 正在回覆中的 group id
     */
    public function filterRunning(array $groupIds)
    {
        if (blank($groupIds)) {
            return [];
        }

        $keys = [];
        foreach ($groupIds as $groupId) {
            $keys[$this->cacheKey($groupId)] = (int) $groupId;
        }

        $cached = Cache::many(array_keys($keys));

        $running = [];
        foreach ($cached as $key => $value) {
            if (filled($value)) {
                $running[] = $keys[$key];
            }
        }

        return $running;
    }

    /**
     * 廣播狀態
     *
     * 廣播失敗不該影響回覆本身 —— 客人收不收得到答案比畫面提示重要得多。
     * 前端另有安全逾時，提示不會因為這裡失敗就永遠卡住。
     *
     * @param int  $groupId
     * @param bool $running
     * @return void
     */
    private function broadcast($groupId, $running)
    {
        try {
            event(new AutoReplyProgress($groupId, $running));
        } catch (\Exception $e) {
            Log::warning('自動回覆狀態廣播失敗', [
                'group_id' => $groupId,
                'running'  => $running,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param int $groupId
     * @return string
     */
    private function cacheKey($groupId)
    {
        /*
         * 一定要擋掉讀不到設定的情況：部署時忘了更新 config 快取的話，
         * 這個新加的設定會是 null，key 就成了純數字「1」「2」——
         * 會撞到別人的快取，讀到錯的資料，forget 還會把別人的東西刪掉，
         * 而且整個過程完全靜默。
         *
         * 不能只靠 config() 的第二個參數 —— 那只在 key 不存在時生效，
         * key 存在但值是 null 時照樣回 null。
         */
        $prefix = config('auto_reply.progress_cache_prefix');

        if (blank($prefix)) {
            $prefix = self::FALLBACK_PREFIX;
        }

        return "{$prefix}{$groupId}";
    }

    /**
     * 快取秒數
     *
     * 讀不到設定時 (int) null 會是 0，而 TTL 0 等於立刻過期 ——
     * 提示會在畫面上閃一下就消失，比沒做還糟。
     *
     * @return int
     */
    private function cacheSeconds()
    {
        $seconds = (int) config('auto_reply.progress_cache_seconds');

        return $seconds > 0 ? $seconds : self::FALLBACK_SECONDS;
    }
}

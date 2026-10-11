<?php

namespace App\Services\AutoReply;

use App\Services\AppSettingService;
use App\Services\StaffIgnoreService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 等待詞偵測
 *
 * 判斷一則訊息是不是在說「我稍後會提供某個東西 / 稍後會處理某件事」，
 * 以及那句話算誰承諾的。
 *
 * 兩段式，便宜的先跑：
 *
 * 1. **詞表比對** —— 絕大多數訊息停在這裡，零成本
 * 2. **模型確認** —— 只在命中時跑一次是非題
 *
 * ⚠ **為什麼不能只靠模型**：客人訊息不保證會經過 AI。`TelegramChatService`
 * 那三道判斷（isAutoReplyOn / isStaff / isIgnored）任一道不過就不會跑自動回覆
 * —— 而關掉自動回覆的對話（客服正在親自處理的那些）反而最需要這個功能。
 *
 * ⚠ **為什麼不能只靠詞表**：「等等的費率問題」「我稍後再看一下文件」都含那些字
 * 但不是承諾。誤判的代價是內部群組多一則訊息，而**雜訊會讓整個功能被忽略**。
 *
 * ⚠ **模型不可用時退回「只靠詞表」**（回命中的詞而不是 null）：
 * 額度用完、CLI 逾時都會發生，那時候寧可多開幾筆讓人按「不追了」，
 * 也不要讓功能靜默停擺 —— 後者沒有人會發現。
 */
class WaitDetector
{
    private $matcher;
    private $staffIgnoreService;
    private $appSettingService;

    public function __construct(
        ClaudeCodeMatcher $matcher,
        StaffIgnoreService $staffIgnoreService,
        AppSettingService $appSettingService
    ) {
        $this->matcher = $matcher;
        // 客戶群裡講話的人是不是自己人，靠這份全站共用的名單
        $this->staffIgnoreService = $staffIgnoreService;
        $this->appSettingService = $appSettingService;
    }

    /**
     * 這句話算誰承諾的
     *
     * | 訊息 | 判定 |
     * |---|---|
     * | 收到的，發話者不是後台帳號 | 客人承諾 |
     * | 收到的，發話者是後台帳號（同事用個人帳號在客戶群講話） | 我方承諾 |
     * | 後台送出的，不是自動回覆 | 我方承諾 |
     * | 後台送出的，**是自動回覆** | 不算（回 null） |
     *
     * ⚠ **AI 自動回覆那句一定要排除**：轉人工的稍等句（`WaitWriter` 寫的）
     * 必然含「稍等」，而那條路**已經開了求助單、已經在催了** ——
     * 不排除的話每次轉人工都會多一筆待追蹤事項，兩套提醒追同一件事。
     *
     * @param array $context direction / is_auto / from（Telegram 的 from）/ group_id
     * @return int|null 見 constants.FOLLOWUP.SOURCE；不該開單時回 null
     */
    public function source(array $context)
    {
        $sources = (array) config('constants.FOLLOWUP.SOURCE');
        $inbound = (int) config('constants.TELEGRAM.DIRECTION.INBOUND');

        if ((int) Arr::get($context, 'direction') !== $inbound) {
            // 後台送出的：自動回覆不算，客服手打的算我方承諾
            return filled(Arr::get($context, 'is_auto'))
                ? null
                : (int) Arr::get($sources, 'STAFF');
        }

        $from = (array) Arr::get($context, 'from', []);
        $groupId = (int) Arr::get($context, 'group_id');

        /*
         * 自己人用個人帳號在客戶群講的「稍等」—— 那是我方承諾。
         *
         * 這裡**刻意不短路掉**：`isStaff()` 要讀名單，但客戶群裡同事講話
         * 是常態，分不出來的話我方承諾會全部被記成客人承諾，
         * 按鈕按下去就會發訊息去問客人「進度如何」—— 語意完全反過來。
         */
        if (filled($from) && $this->staffIgnoreService->isStaff($from, $groupId)) {
            return (int) Arr::get($sources, 'STAFF');
        }

        return (int) Arr::get($sources, 'CUSTOMER');
    }

    /**
     * 這句話是不是在說「稍後給你」
     *
     * @param string $text
     * @return string|null 命中的詞；不是等待詞時回 null
     */
    public function match($text)
    {
        $text = trim((string) $text);

        if (blank($text)) {
            return null;
        }

        $keyword = $this->matchKeyword($text);

        // 詞表都沒命中 —— 絕大多數訊息走到這裡就結束，不花模型額度
        if (blank($keyword)) {
            return null;
        }

        if ($this->confirm($text) === false) {
            Log::info('等待詞被模型否決', [
                'keyword' => $keyword,
                'text'    => mb_substr($text, 0, 100),
            ]);

            return null;
        }

        return $keyword;
    }

    /**
     * 詞表比對
     *
     * @param string $text
     * @return string|null
     */
    private function matchKeyword($text)
    {
        foreach ($this->keywords() as $keyword) {
            if (mb_strpos($text, $keyword) !== false) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * 要比對的詞
     *
     * 設定頁一行一個，留白就用 config 的預設。
     *
     * @return array<string>
     */
    private function keywords()
    {
        $value = (string) $this->appSettingService->get(AppSettingService::KEY_FOLLOWUP_KEYWORDS);

        if (blank($value)) {
            return (array) config('constants.FOLLOWUP.KEYWORDS');
        }

        $keywords = [];

        foreach (preg_split('/\r\n|\r|\n/', $value) as $line) {
            $line = trim($line);

            if (filled($line)) {
                $keywords[] = $line;
            }
        }

        // 設定存了但每一行都是空白 —— 當成沒設定，不要變成「什麼都不偵測」
        return filled($keywords) ? $keywords : (array) config('constants.FOLLOWUP.KEYWORDS');
    }

    /**
     * 問模型是不是真的在說稍後提供
     *
     * ⚠ 回 `null` 是「問不到」（沒設定 token、額度用完、CLI 逾時），
     * 跟 `false`（確定不是）不一樣 —— 呼叫端只在明確的 `false` 時放棄。
     *
     * @param string $text
     * @return bool|null
     */
    private function confirm($text)
    {
        $config = (array) config('constants.FOLLOWUP.DETECT');

        try {
            return $this->matcher->generateFlag(
                (string) Arr::get($config, 'PROMPT'),
                strtr((string) Arr::get($config, 'INPUT'), ['{text}' => $text]),
                'is_waiting'
            );
        } catch (\Exception $e) {
            // 偵測失敗不能讓訊息處理整條斷掉 —— 這是輔助功能
            Log::warning('等待詞確認失敗，退回只靠詞表', ['error' => $e->getMessage()]);

            return null;
        }
    }
}

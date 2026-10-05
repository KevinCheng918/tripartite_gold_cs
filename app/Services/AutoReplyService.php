<?php

namespace App\Services;

use App\Contracts\AutoReplyMatcher;
use App\Models\TelegramGroup;
use App\Repositories\QuickReplyRepository;
use App\Repositories\StationRepository;
use App\Repositories\TelegramRepository;
use App\Services\AutoReply\AnswerSplitter;
use App\Services\AutoReply\OpeningSanitizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 自動回覆決策服務
 *
 * 拿比對器的判斷，決定要送答案、只回一句、說稍等並轉人工，還是什麼都不回。
 *
 * 兩個原則：
 *
 * 1. **答案本體永遠是題庫原文**。模型只能寫「承接客人的那一兩句」（opening），
 *    而且還要通過 sanitizeOpening() 才採用 —— 沒過就退回固定話術。
 * 2. **拿不準就轉人工**。答錯的代價遠高於多轉一次人工，
 *    而且每轉一次就是一次把題庫補起來的機會。
 *
 * 比對器只管判斷，這一層管流程；實際送訊息仍走 TelegramChatService::sendReply()。
 */
class AutoReplyService
{
    /**
     * config 讀不到時的備用脈絡設定，理由見 contextSetting()。
     *
     * @var array
     */
    private const FALLBACK_CONTEXT = [
        'limit'     => 6,
        'minutes'   => 15,
        'max_chars' => 600,
        'total'     => 3000,
    ];

    private $matcher;
    private $telegramRepository;
    private $quickReplyRepository;
    private $chatService;
    private $supportService;
    private $appSettingService;
    private $dailyRateService;
    private $stationRepository;
    private $paymentConfigService;
    private $splitter;

    public function __construct(
        AutoReplyMatcher $matcher,
        TelegramRepository $telegramRepository,
        QuickReplyRepository $quickReplyRepository,
        TelegramChatService $chatService,
        AutoReplySupportService $supportService,
        AppSettingService $appSettingService,
        DailyRateService $dailyRateService,
        StationRepository $stationRepository,
        PaymentConfigService $paymentConfigService,
        AnswerSplitter $splitter
    ) {
        $this->matcher = $matcher;
        $this->telegramRepository = $telegramRepository;
        $this->quickReplyRepository = $quickReplyRepository;
        $this->chatService = $chatService;
        $this->supportService = $supportService;
        $this->appSettingService = $appSettingService;
        // 只有匯率題用得到：答案每天不同，送出前要換成當日報價
        $this->dailyRateService = $dailyRateService;
        // 同上 —— 匯率已決定時要改發補點訊息，得先從對話反查站台所屬的系統
        $this->stationRepository = $stationRepository;
        $this->paymentConfigService = $paymentConfigService;
        $this->splitter = $splitter;
    }

    /**
     * 處理一則客人訊息
     *
     * @param int         $groupId
     * @param string      $text      客人的話
     * @param int|null    $messageId 客人那則訊息的後台 id（開求助單用）
     * @return void
     */
    public function handle($groupId, $text, $messageId = null)
    {
        $group = $this->telegramRepository->findGroup($groupId);

        if (!$group || !$group->isAutoReplyOn()) {
            return;
        }

        // 脈絡要在比對之前取：客人常常分兩則講一件事（先貼錯誤訊息、再問
        // 「這是什麼錯誤呢」），只送後面那一句進去必然比不到
        $history = $this->buildHistory($group->id, $messageId);

        $result = $this->matcher->resolve($text, [
            'group_id' => $group->id,
            'history'  => $history,
        ]);

        /*
         * AI 跑完了，但客人在這期間又說話了。
         *
         * 這是第二道「我還是最後一則嗎」—— 第一道在 `AutoReplyJob` 開始前。
         * CLI 要跑數秒到數十秒，客人很容易在那段時間補上第二句，
         * **少了這一道，連著傳的三句話仍然會被回三次**。
         *
         * 擺在兩個分支之前：命中答案與轉人工都要跳過。不然連發時會開出
         * 好幾張內容幾乎一樣的求助單，同仁得一張張關掉。
         *
         * 不必擔心漏回：最後那則會負責回覆，而且它的脈絡本來就帶得到
         * 前面幾句（`auto_reply.context`）。
         */
        if ($this->telegramRepository->hasNewerInbound($group->id, $messageId)) {
            Log::info('客人已有更新的訊息，這則的答案不送出', [
                'group_id'   => $group->id,
                'message_id' => $messageId,
                'stage'      => 'AI 跑完後',
            ]);

            return;
        }

        // 比對器掛了（逾時、額度用盡、格式壞掉）—— 一律走人工，不要讓客人空等
        if (blank($result)) {
            $this->replyWait($group, $text, null, $messageId, [], $history);

            return;
        }

        $this->dispatchResult($group, $result, $text, $messageId, $history);
    }

    /**
     * 取這個對話的近期訊息，轉成一行一則的文字
     *
     * 格式化只做這一次 —— 送進模型的脈絡與求助單的前情用的是同一份，
     * 同仁在支援群組看到的就等於模型看到的。
     *
     * @param int      $groupId
     * @param int|null $messageId 客人現在這一則，它自己不算脈絡
     * @return array<int, string> 依時間正序，舊的在前
     */
    private function buildHistory($groupId, $messageId)
    {
        $limit = $this->contextSetting('limit');

        // 設定關掉（填 0）就整個不撈，省一趟查詢
        if ($limit < 1) {
            return [];
        }

        $messages = $this->telegramRepository->getRecentMessages(
            $groupId,
            $messageId,
            $limit,
            $this->contextSetting('minutes')
        );

        $inbound = config('constants.TELEGRAM.DIRECTION.INBOUND');
        $maxChars = $this->contextSetting('max_chars');
        $lines = [];

        foreach ($messages as $message) {
            $content = trim((string) $message->content);

            // 圖片、檔案、語音沒有文字，但要讓模型知道上一則真的有東西 ——
            // 整則跳過的話，客人問「這張圖是什麼意思」就會變成沒頭沒尾
            if (blank($content)) {
                $labels = config('constants.TELEGRAM.MEDIA_LABELS');
                $content = Arr::get($labels, $message->media_type, Arr::get($labels, 'default'));
            }

            if (mb_strlen($content) > $maxChars) {
                $content = mb_substr($content, 0, $maxChars) . '…（略）';
            }

            // 換行全部壓成空白：一則佔一行，模型才分得出哪裡是一則的開始
            $content = preg_replace('/\s+/u', ' ', $content);

            $lines[] = $message->direction === $inbound
                ? "客人 {$message->sender_name}：{$content}"
                : "客服：{$content}";
        }

        return $this->trimHistory($lines);
    }

    /**
     * 砍到總長度上限內
     *
     * **從最舊的開始丟** —— 離客人這句話越近的越可能是他在指的東西。
     *
     * @param array<int, string> $lines
     * @return array<int, string>
     */
    private function trimHistory(array $lines)
    {
        $total = $this->contextSetting('total');

        while (count($lines) > 0 && mb_strlen(implode("\n", $lines)) > $total) {
            array_shift($lines);
        }

        return $lines;
    }

    /**
     * 讀脈絡設定，config 讀不到就用備用值
     *
     * ⚠️ 這個備用值是必要的：只要 config 快取沒跟著部署更新，
     * `auto_reply.context.*` 全部會是 null，limit 變成 0 —— 功能**整個靜默關閉**，
     * 不會報錯、不會寫 log，只是客人的追問又開始轉人工，很難察覺。
     *
     * 刻意填 0 關掉脈絡不會被蓋掉：blank(0) 是 false。
     *
     * @param string $key limit / minutes / max_chars / total
     * @return int
     */
    private function contextSetting($key)
    {
        $value = config("auto_reply.context.{$key}");

        if (blank($value)) {
            return (int) Arr::get(self::FALLBACK_CONTEXT, $key);
        }

        return (int) $value;
    }

    /**
     * 切換某個對話的自動回覆開關
     *
     * @param int  $groupId
     * @param bool $enabled
     * @return TelegramGroup
     * @throws \RuntimeException
     */
    public function toggle($groupId, $enabled)
    {
        $group = $this->telegramRepository->findGroup($groupId);

        if (blank($group)) {
            throw new \RuntimeException(trans('telegram_chat.msg.group_not_found'));
        }

        // 沒設定 Claude 就不讓開 —— 開了也只會讓客人一直收到「稍等」
        if ($enabled && !$this->isAvailable()) {
            throw new \RuntimeException(trans('telegram_chat.msg.auto_reply_unavailable'));
        }

        return $this->telegramRepository->setAutoReply($group, $enabled);
    }

    /**
     * 自動回覆是否可用（Claude 憑證已設定）
     *
     * @return bool
     */
    public function isAvailable()
    {
        return $this->appSettingService->has(AppSettingService::KEY_CLAUDE_TOKEN);
    }

    /**
     * 依比對結果決定怎麼回
     *
     * @param TelegramGroup $group
     * @param array         $result
     * @param string        $text
     * @param int|null      $messageId
     * @param array         $history   近期對話，轉人工時一起附進求助訊息
     * @return void
     */
    private function dispatchResult(TelegramGroup $group, array $result, $text, $messageId, array $history = [])
    {
        $decision = $this->decide($result, $group);
        $actions = config('constants.AUTO_REPLY.DECISION');

        // 客人只說了「好」「收到」——再回一句才是打擾。
        // 刻意不標記已回覆：萬一這是誤判（客人其實有問事情），
        // 既有的未回覆告警仍會響，客服就會接手
        if ($decision['action'] === $actions['SILENT']) {
            Log::info('自動回覆判定不需回應', ['group_id' => $group->id, 'text' => $text]);

            return;
        }

        if ($decision['action'] === $actions['ANSWER']) {
            $this->replyWithItem($group, $decision['item'], $decision['opening']);

            return;
        }

        // 資訊不足：先跟客人要資料，內容一樣是題庫原文
        if ($decision['action'] === $actions['ASK_INFO']) {
            Log::info('自動回覆判定資訊不足，先向客人索取', [
                'group_id' => $group->id,
                'item_id'  => $decision['item']->id,
                'text'     => $text,
            ]);

            $this->replyAskInfo($group, $decision['item'], $decision['opening']);

            return;
        }

        if ($decision['action'] === $actions['REPLY']) {
            $this->replyOpeningOnly($group, $decision['opening']);

            return;
        }

        $this->replyWait($group, $text, $decision['opening'], $messageId, $result, $history);
    }

    /**
     * 決策：這個比對結果該怎麼回
     *
     * 只讀不寫 —— 送出訊息與更新狀態都由呼叫端負責，
     * 這樣 auto-reply:test 可以重用同一套判斷而不會有副作用，
     * 測出來的結果也才等於線上真正會做的事。
     *
     * @param array              $result 比對器的輸出
     * @param TelegramGroup|null $group  追問冷卻要看是哪個對話；預覽沒有對話，傳 null
     * @return array action（見 constants.AUTO_REPLY.DECISION）／item／opening
     */
    private function decide(array $result, ?TelegramGroup $group = null)
    {
        $actions = config('constants.AUTO_REPLY.DECISION');
        $intents = config('constants.AUTO_REPLY.INTENT');
        $intent = Arr::get($result, 'intent');
        $opening = $this->sanitizeOpening(Arr::get($result, 'opening'));

        // 寒暄：有話要回就回一句，沒有就閉嘴
        if ($intent === $intents['CHAT']) {
            $action = filled($opening) ? $actions['REPLY'] : $actions['SILENT'];

            return ['action' => $action, 'item' => null, 'opening' => $opening];
        }

        // 需求不是題庫該回答的東西 —— 硬比對只會撈出不相干的答案，一律轉人工
        if ($intent === $intents['REQUEST']) {
            return ['action' => $actions['WAIT'], 'item' => null, 'opening' => $opening];
        }

        $itemId = Arr::get($result, 'item_id');

        /*
         * 資訊不足：先跟客人要資料，不要拿題庫答案硬回。
         *
         * 客人只丟一句「訂單沒收到款」，同仁得先問代理帳號與訂單號才查得下去。
         * 以前這種訊息會被當成一般提問轉人工，同仁收到求助單卻只能按忽略 ——
         * 那次對話就白費了。
         *
         * ⚠ 追問的內容一定是題庫原文（「需要補充資訊」那一類），模型只負責
         * 判斷「該不該先問」。題庫還沒有相符的題目時 item_id 會是 null，
         * 那就照舊轉人工 —— 寧可轉人工，也不要讓它自己編要問什麼。
         */
        // 冷卻判斷（讀 Cache）排在查題庫之前 —— 冷卻中就直接跳過，不必白查一次 DB
        if (Arr::get($result, 'needs_info') === true && filled($itemId) && $this->canAskInfo($group)) {
            $item = $this->quickReplyRepository->findActiveItem($itemId);

            if (filled($item)) {
                return ['action' => $actions['ASK_INFO'], 'item' => $item, 'opening' => $opening];
            }
        }

        $isHigh = Arr::get($result, 'confidence') === config('constants.AUTO_REPLY.CONFIDENCE.HIGH');

        if ($isHigh && filled($itemId)) {
            $item = $this->quickReplyRepository->findActiveItem($itemId);

            if (filled($item)) {
                return ['action' => $actions['ANSWER'], 'item' => $item, 'opening' => $opening];
            }

            // 模型挑到的題目剛好被停用或刪掉，當作沒命中
            Log::warning('自動回覆命中的題目已不存在或已停用', ['item_id' => $itemId]);
        }

        // 沒把握就轉人工。答錯的代價遠高於多轉一次人工，
        // 而且每轉一次就是一次把題庫補起來的機會
        return ['action' => $actions['WAIT'], 'item' => null, 'opening' => $opening];
    }

    /**
     * 這個對話現在可以追問嗎
     *
     * ⚠️ **這道冷卻是必要的，不要拿掉。**
     * 客人補了資料之後，模型有可能又覺得「還是不夠」而再問一次 ——
     * 一來一往變成無止境的追問，客人會直接炸掉。
     *
     * 同一個對話在冷卻期間內只追問一次；第二次即使判斷資訊不足也照舊轉人工，
     * 交給同仁判斷還缺什麼。
     *
     * 預覽沒有對話（$group 是 null），不受冷卻限制 ——
     * 那是用來測判斷結果的，擋掉反而測不出來。
     *
     * @param TelegramGroup|null $group
     * @return bool
     */
    private function canAskInfo($group)
    {
        if (blank($group)) {
            return true;
        }

        return !Cache::has($this->askInfoCacheKey($group->id));
    }

    /**
     * 記下「這個對話剛剛追問過」
     *
     * @param TelegramGroup $group
     * @return void
     */
    private function markAsked(TelegramGroup $group)
    {
        $minutes = (int) config('auto_reply.ask_info_cooldown');

        // 設定成 0 等於關掉冷卻，那是刻意的選擇，不要用 fallback 蓋掉
        if ($minutes < 1) {
            return;
        }

        Cache::put($this->askInfoCacheKey($group->id), true, now()->addMinutes($minutes));
    }

    /**
     * @param int $groupId
     * @return string
     */
    private function askInfoCacheKey($groupId)
    {
        return "auto_reply.asked.{$groupId}";
    }

    /**
     * 先跟客人要資料
     *
     * 走的是跟命中答案同一套模板與分則邏輯 —— 對客人來說，
     * 「請提供訂單號」跟一般回覆沒有差別，不該長得不一樣。
     *
     * ⚠️ **不標記已回覆**：這件事還沒處理完，客人補資料之前
     * 既有的未回覆告警要繼續響，同仁才不會漏掉。
     *
     * @param TelegramGroup $group
     * @param object        $item
     * @param string|null   $opening
     * @return void
     */
    private function replyAskInfo(TelegramGroup $group, $item, $opening)
    {
        // 跟命中答案一樣：承接句 + 題庫原文，不包外殼
        $this->send($group, $this->joinOpening($opening, $item->answer), false);
        $this->markAsked($group);
        $this->telegramRepository->updateAutoReplyState($group, $item->id);
    }

    /**
     * 檢查承接句能不能用
     *
     * 這是模型唯一能自由生成的地方，所以 prompt 講過的限制這裡要再擋一次 ——
     * prompt 是請求，不是保證。任何一條不過就退回固定話術，
     * 最壞情況等於改版前，不會更糟。
     *
     * @param string|null $opening
     * @return string|null 可用的承接句；不可用時為 null
     */
    private function sanitizeOpening($opening)
    {
        // 實作搬到 OpeningSanitizer —— 轉同仁答案那條路徑也要用同一組護欄
        return app(OpeningSanitizer::class)->sanitize($opening);
    }

    /**
     * 試跑一次比對，回傳決策與實際會送出的內容（不送訊息、不寫任何狀態）
     *
     * 供 auto-reply:test 調 prompt 與話術用。
     *
     * @param string $text
     * @return array action／content／hint／result
     */
    public function preview($text)
    {
        $result = $this->matcher->resolve($text);
        $actions = config('constants.AUTO_REPLY.DECISION');

        if (blank($result)) {
            return [
                'action'  => $actions['WAIT'],
                'content' => $this->previewContent($actions['WAIT'], null, null),
                'hint'    => null,
                'result'  => null,
            ];
        }

        $decision = $this->decide($result);

        return [
            'action'  => $decision['action'],
            'content' => $this->previewContent($decision['action'], $decision['item'], $decision['opening']),
            // 轉人工時支援群組會多收到這段，測試時一併看得到
            'hint'    => $decision['action'] === $actions['WAIT'] ? $this->buildHint($result) : null,
            'result'  => $result,
        ];
    }

    /**
     * 組出預覽用的訊息內容
     *
     * 一律用完整版模板 —— 預覽沒有對話脈絡可以判斷該用哪一版。
     *
     * @param string                          $action
     * @param \App\Models\QuickReplyItem|null $item
     * @param string|null                     $opening
     * @return string
     */
    private function previewContent($action, $item, $opening)
    {
        $actions = config('constants.AUTO_REPLY.DECISION');
        $signature = config('constants.AUTO_REPLY.SIGNATURE');

        // 什麼都不送
        if ($action === $actions['SILENT']) {
            return '';
        }

        // 追問跟命中答案送出去的東西一樣（承接句 + 題庫原文），預覽自然也一樣
        if ($action === $actions['ANSWER'] || $action === $actions['ASK_INFO']) {
            $content = $this->joinOpening($opening, $item->answer);
        } elseif ($action === $actions['REPLY']) {
            $content = (string) $opening;
        } else {
            $content = filled($opening) ? $opening : (string) config('auto_reply.templates.wait');
        }

        return filled($content) ? "{$content} {$signature}" : '';
    }

    /**
     * 送出題庫答案
     *
     * 承接句在前、題庫答案原文在後。答案一個字都不改 ——
     * 客戶看到的說明內容永遠等於某個人寫過的內容，這樣才查得回去。
     *
     * @param TelegramGroup              $group
     * @param \App\Models\QuickReplyItem $item
     * @param string|null                $opening
     * @return void
     */
    private function replyWithItem(TelegramGroup $group, $item, $opening)
    {
        /*
         * 送出去的就是「承接句 + 題庫原文」，不再包話術外殼。
         *
         * 以前這裡會套一層完整版模板（「您好，感謝您的詢問 😊…」），結果
         * 第一次對話會連續兩個開頭問候 —— 承接句一個、模板一個。
         * 開頭交給模型、答案用題庫原文，本來就不需要中間那層。
         */
        /*
         * 匯率題有自己的送法：今天的匯率已經決定時，直接給完整的補點訊息
         * （含繳款圖）而不是只回一句報價 —— 客人問匯率通常就是要補點。
         *
         * 不適用時回 false，就走下面的一般路徑（匯率未定 → 「稍後為您確認」）。
         */
        if ($this->isRateItem($item) && $this->sendRateTopup($group)) {
            $this->telegramRepository->updateAutoReplyState($group, $item->id);

            return;
        }

        // 命中答案沒有冷卻 —— 客人重複問同一件事，就重複回答
        $this->send($group, $this->joinOpening($opening, $this->resolveAnswer($item)), true);
        $this->telegramRepository->updateAutoReplyState($group, $item->id);
    }

    /**
     * 這是不是匯率那一題
     *
     * @param mixed $item
     * @return bool
     */
    private function isRateItem($item)
    {
        return $item->import_key === config('constants.DAILY_RATE.QUICK_REPLY_KEY');
    }

    /**
     * 客人問匯率、而今天的匯率已經決定 —— 直接發補點訊息（含繳款圖）
     *
     * 補點訊息公版本身就帶 `{rate}`，所以匯率資訊沒有消失，客人還多拿到
     * 「補 N 點要多少 USDT」與匯款資訊，省掉一輪往返。
     *
     * 三種情況退回原本的報匯率（回 false）：
     *
     * | 情況 | 為什麼 |
     * |---|---|
     * | 匯率還沒決定 | `customerAnswer()` 會回「稍後為您確認」，那是對的 |
     * | 這個對話找不到對應站台 | 不知道該用哪一筆繳款設定 |
     * | 站台所屬系統沒填補點訊息 | 沒東西可發 |
     *
     * 後兩種是**靜默退回** —— 客人還是得到有用的答案（匯率），只是少了匯款資訊。
     * 但要記 info log，否則「為什麼這個群組沒收到補點訊息」查不出來。
     *
     * ⚠ 整段包 try/catch：這是**附帶的加值**，查詢或組裝噴錯時退回報匯率就好，
     * 不能讓客人連匯率都問不到（同 StationCreditAlertService 的那條教訓）。
     *
     * ⚠ **這一則不加 AI 承接句。** 承接句的內容不可控，實際上出現過
     * 「關於匯率的部分我這邊確認一下～」接著下一行就給出完整答案 ——
     * 說要去確認、卻又立刻回答，客人看了會覺得前後矛盾。
     *
     * 補點訊息本身就是完整的答案（日期、匯率、換算、匯款資訊），
     * 不需要前導句。要加招呼語的話請加在繳款設定的補點訊息公版開頭，
     * 那是客服寫得了、看得到的地方。
     *
     * @param TelegramGroup $group
     * @return bool 有沒有發出去
     */
    private function sendRateTopup(TelegramGroup $group)
    {
        try {
            $rate = $this->dailyRateService->todayRate();

            if (blank($rate)) {
                return false;
            }

            $station = $this->stationRepository->findByTelegramGroupId($group->id);

            if (blank($station) || blank($station->system_id)) {
                Log::info('客人問匯率：這個對話找不到對應站台，改回報匯率', [
                    'group_id' => $group->id,
                ]);

                return false;
            }

            $config = $this->paymentConfigService
                ->getActiveBySystem((int) $station->system_id)
                ->first();

            $topup = $this->paymentConfigService->buildTopupMessage($config, $rate);
            $text = Arr::get($topup, 'text');

            if (blank($text)) {
                Log::info('客人問匯率：這個系統沒填補點訊息，改回報匯率', [
                    'group_id'  => $group->id,
                    'station'   => $station->name,
                    'system_id' => $station->system_id,
                ]);

                return false;
            }

            $this->sendWithImage($group, $text, Arr::get($topup, 'image_url'));

            return true;
        } catch (\Exception $e) {
            Log::error('客人問匯率：組補點訊息失敗，改回報匯率', [
                'group_id' => $group->id,
                'error'    => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 送一則帶圖的回覆（不分段）
     *
     * ⚠ 刻意**不經過 splitter** —— 補點訊息公版本來就是設計成一則，
     * 而分段之後圖只能掛在其中一則上，文字與圖就被拆開了。
     *
     * @param TelegramGroup $group
     * @param string        $content
     * @param string|null   $imageUrl
     * @return void
     */
    private function sendWithImage(TelegramGroup $group, $content, $imageUrl)
    {
        try {
            $this->chatService->sendReply(
                $group->id,
                $content,
                null,
                config('constants.AUTO_REPLY.SENDER_NAME'),
                [
                    'mark_replied' => true,
                    'signature'    => config('constants.AUTO_REPLY.SIGNATURE'),
                    'is_auto'      => true,
                    'image_url'    => $imageUrl,
                ]
            );
        } catch (\Exception $e) {
            Log::error('自動回覆送出失敗（帶圖）', [
                'group_id' => $group->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * 題庫答案，必要時換成動態內容
     *
     * 絕大多數題目送的就是題庫原文。少數題目的答案會變（目前只有匯率）——
     * 那種題目在題庫裡用 import_key 標記，比對照常進行（準度沿用現有機制、
     * 不用改 prompt），只在**要送出之前**把內容換掉。
     *
     * @param mixed $item 題庫項目
     * @return string
     */
    private function resolveAnswer($item)
    {
        /*
         * 走到這裡的匯率題一定是「沒辦法發補點訊息」的那幾種
         * （匯率未定、找不到站台、沒填公版）—— 見 sendRateTopup()。
         * customerAnswer() 會依匯率有沒有決定回報價或「稍後為您確認」。
         */
        if ($this->isRateItem($item)) {
            return $this->dailyRateService->customerAnswer();
        }

        return (string) $item->answer;
    }

    /**
     * 只回一句承接話，不帶任何題庫內容
     *
     * 客人是在寒暄或道謝，沒有東西要查。
     *
     * @param TelegramGroup $group
     * @param string        $opening
     * @return void
     */
    private function replyOpeningOnly(TelegramGroup $group, $opening)
    {
        $this->send($group, $opening, true);
        $this->telegramRepository->updateAutoReplyState($group, null);
    }

    /**
     * 說稍等，並把問題轉到內部支援群組
     *
     * @param TelegramGroup $group
     * @param string        $question
     * @param string|null   $opening   承接句；沒有或被擋下時退回固定話術
     * @param int|null      $messageId
     * @param array         $result    比對器的輸出，附在求助訊息裡給同仁參考
     * @param array         $history   近期對話，附在求助訊息裡讓同仁不用切視窗
     * @return void
     */
    private function replyWait(TelegramGroup $group, $question, $opening, $messageId, array $result = [], array $history = [])
    {
        /*
         * 這裡以前有「5 分鐘內剛說過稍等就不再回」的冷卻，已移除。
         *
         * 那個 return 擋在開求助單前面，造成客人在 5 分鐘內問的第二個問題
         * 既沒有收到回應、也沒有進到支援群組 —— 同仁根本不知道有人問過。
         *
         * 冷卻原本是為了避免「稍等」一再重複顯得敷衍，但現在開頭那句是
         * 依客人的話生成的承接句，每次都不一樣，這個理由已經不存在。
         * 客人問一次就回一次，本來就是客服該做的事。
         */
        // 沒有承接句才退回固定話術 —— 模型逾時、額度用盡、或它判斷不需要回應
        // 的時候會走到這裡，沒有這段客人會完全收不到訊息
        $content = filled($opening) ? $opening : (string) config('auto_reply.templates.wait');

        $this->send($group, $content, false);
        $this->telegramRepository->updateAutoReplyState($group, null);

        $this->supportService->openTicket(
            $group,
            $question,
            $messageId,
            $this->buildHint($result),
            $history,
            (array) Arr::get($result, 'candidates', [])
        );
    }

    /**
     * 把承接句接在內容前面
     *
     * @param string|null $opening
     * @param string      $content
     * @return string
     */
    private function joinOpening($opening, $content)
    {
        if (blank($opening)) {
            return $content;
        }

        return blank($content) ? $opening : "{$opening}\n\n{$content}";
    }

    /**
     * 組給同仁看的 AI 判斷摘要
     *
     * 只出現在內部支援群組，客人看不到。用處是讓同仁不必從頭看就知道客人要什麼，
     * 也一眼看得出題庫缺了哪一塊、值不值得回填。
     *
     * @param array $result 比對器的輸出
     * @return string|null
     */
    private function buildHint(array $result)
    {
        if (blank($result)) {
            return null;
        }

        $intents = config('constants.AUTO_REPLY.INTENT');
        $labels = [
            $intents['QUESTION'] => '提問',
            $intents['REQUEST']  => '需求',
            $intents['CHAT']     => '寒暄',
        ];

        $intent = Arr::get($result, 'intent');
        $lines = ['🤖 AI 判斷：' . Arr::get($labels, $intent, '無法判斷')];

        $itemId = Arr::get($result, 'item_id');

        if (filled($itemId)) {
            $item = $this->quickReplyRepository->findActiveItem($itemId);

            if (filled($item)) {
                $lines[] = "　 最接近的題庫：#{$item->id} {$item->label}（信心不足）";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 送出訊息
     *
     * @param TelegramGroup $group
     * @param string        $content
     * @param bool          $markReplied 是否算已回覆（只有真的答了才算）
     * @return void
     */
    private function send(TelegramGroup $group, $content, $markReplied)
    {
        if (blank($content)) {
            Log::warning('自動回覆話術模板為空，略過送出', ['group_id' => $group->id]);

            return;
        }

        $chunks = $this->splitter->split($content);
        $lastIndex = count($chunks) - 1;

        foreach ($chunks as $index => $chunk) {
            $isLast = $index === $lastIndex;

            try {
                $this->chatService->sendReply(
                    $group->id,
                    $chunk,
                    null,
                    config('constants.AUTO_REPLY.SENDER_NAME'),
                    [
                        // 署名與已回覆標記都只掛在最後一則：
                        // 每則都接一個 -A，客人會看到三個署名
                        'mark_replied' => $markReplied && $isLast,
                        'signature'    => $isLast ? config('constants.AUTO_REPLY.SIGNATURE') : null,
                        'is_auto'      => true,
                    ]
                );
            } catch (\Exception $e) {
                Log::error('自動回覆送出失敗', [
                    'group_id' => $group->id,
                    'chunk'    => $index + 1,
                    'total'    => count($chunks),
                    'error'    => $e->getMessage(),
                ]);

                // 中間那則沒送出去就不要再送後面的 —— 客人會看到跳掉一段的說明，
                // 比整個沒收到更難處理
                return;
            }
        }
    }


    /*
     * renderTemplate() 與 shouldGreet() 在 2026-09-30 移除。
     *
     * 那兩支是「完整版／精簡版話術」的切換：距離上次回覆超過 30 分鐘就帶問候語，
     * 連續對話用精簡版。現在開頭一律由模型的承接句負責，沒有模板可切了。
     */


}

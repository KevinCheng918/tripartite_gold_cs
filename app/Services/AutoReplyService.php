<?php

namespace App\Services;

use App\Contracts\AutoReplyMatcher;
use App\Models\TelegramGroup;
use App\Repositories\QuickReplyRepository;
use App\Repositories\TelegramRepository;
use App\Services\AutoReply\AnswerSplitter;
use Illuminate\Support\Arr;
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
    private $splitter;

    public function __construct(
        AutoReplyMatcher $matcher,
        TelegramRepository $telegramRepository,
        QuickReplyRepository $quickReplyRepository,
        TelegramChatService $chatService,
        AutoReplySupportService $supportService,
        AppSettingService $appSettingService,
        AnswerSplitter $splitter
    ) {
        $this->matcher = $matcher;
        $this->telegramRepository = $telegramRepository;
        $this->quickReplyRepository = $quickReplyRepository;
        $this->chatService = $chatService;
        $this->supportService = $supportService;
        $this->appSettingService = $appSettingService;
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
        $decision = $this->decide($result);
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
     * @param array $result 比對器的輸出
     * @return array action（見 constants.AUTO_REPLY.DECISION）／item／opening
     */
    private function decide(array $result)
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

        $isHigh = Arr::get($result, 'confidence') === config('constants.AUTO_REPLY.CONFIDENCE.HIGH');
        $itemId = Arr::get($result, 'item_id');

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
        if (blank($opening)) {
            return null;
        }

        $opening = trim($opening);
        $maxLength = (int) config('constants.AUTO_REPLY.OPENING.MAX_LENGTH');

        if (mb_strlen($opening) > $maxLength) {
            Log::warning('承接句過長，退回固定話術', ['opening' => $opening]);

            return null;
        }

        foreach ((array) config('constants.AUTO_REPLY.OPENING.BLACKLIST') as $word) {
            if (mb_strpos($opening, $word) !== false) {
                Log::warning('承接句含不該由系統說的話，退回固定話術', [
                    'word'    => $word,
                    'opening' => $opening,
                ]);

                return null;
            }
        }

        return $opening;
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

        if ($action === $actions['ANSWER']) {
            $template = $this->appSettingService->get(AppSettingService::KEY_TPL_ANSWER_FULL);
            $content = filled($template) ? strtr($template, ['{答案}' => $item->answer]) : '';
            $content = $this->joinOpening($opening, $content);
        } elseif ($action === $actions['REPLY']) {
            $content = (string) $opening;
        } else {
            $content = filled($opening)
                ? $opening
                : (string) $this->appSettingService->get(AppSettingService::KEY_TPL_WAIT_FULL);
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
        $content = $this->renderTemplate(
            $group,
            AppSettingService::KEY_TPL_ANSWER_FULL,
            AppSettingService::KEY_TPL_ANSWER_SHORT,
            ['{答案}' => $item->answer]
        );

        // 命中答案沒有冷卻 —— 客人重複問同一件事，就重複回答
        $this->send($group, $this->joinOpening($opening, $content), true);
        $this->telegramRepository->updateAutoReplyState($group, $item->id);
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
        $content = filled($opening) ? $opening : $this->renderTemplate(
            $group,
            AppSettingService::KEY_TPL_WAIT_FULL,
            AppSettingService::KEY_TPL_WAIT_SHORT,
            []
        );

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


    /**
     * 套用話術模板
     *
     * 距離上次自動回覆超過一段時間才用帶問候語的完整版，
     * 連續對話用精簡版 —— 每則都「您好，感謝您的詢問」很快就顯得虛假。
     *
     * @param TelegramGroup $group
     * @param string        $fullKey
     * @param string        $shortKey
     * @param array         $replacements 變數 => 值
     * @return string
     */
    private function renderTemplate(TelegramGroup $group, $fullKey, $shortKey, array $replacements)
    {
        $key = $this->shouldGreet($group) ? $fullKey : $shortKey;
        $template = $this->appSettingService->get($key);

        // 精簡版沒設定就退回完整版，寧可囉唆也不要送出空訊息
        if (blank($template)) {
            $template = $this->appSettingService->get($fullKey);
        }

        if (blank($template)) {
            return '';
        }

        return strtr($template, $replacements);
    }

    /**
     * 這次要不要用帶問候語的完整版
     *
     * @param TelegramGroup $group
     * @return bool
     */
    private function shouldGreet(TelegramGroup $group)
    {
        if (blank($group->auto_reply_at)) {
            return true;
        }

        $minutes = (int) config('constants.AUTO_REPLY.GREETING_GAP_MINUTES');

        return $group->auto_reply_at->lt(now()->subMinutes($minutes));
    }


}

<?php

namespace App\Services;

use App\Contracts\AutoReplyMatcher;
use App\Models\TelegramGroup;
use App\Repositories\QuickReplyRepository;
use App\Repositories\TelegramRepository;
use Illuminate\Support\Facades\Log;

/**
 * 自動回覆決策服務
 *
 * 拿比對器挑出來的題目，決定要「送答案」「反問」還是「說稍等並轉人工」，
 * 並負責把答案包進對客話術。
 *
 * 比對器只管挑題目，這一層管流程；實際送訊息仍走 TelegramChatService::sendReply()。
 */
class AutoReplyService
{
    private $matcher;
    private $telegramRepository;
    private $quickReplyRepository;
    private $chatService;
    private $supportService;
    private $appSettingService;

    public function __construct(
        AutoReplyMatcher $matcher,
        TelegramRepository $telegramRepository,
        QuickReplyRepository $quickReplyRepository,
        TelegramChatService $chatService,
        AutoReplySupportService $supportService,
        AppSettingService $appSettingService
    ) {
        $this->matcher = $matcher;
        $this->telegramRepository = $telegramRepository;
        $this->quickReplyRepository = $quickReplyRepository;
        $this->chatService = $chatService;
        $this->supportService = $supportService;
        $this->appSettingService = $appSettingService;
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

        $context = $this->buildContext($group);

        // 客人回「1」這種編號，不必再問一次模型
        $chosen = $this->matchNumericChoice($text, $context);

        if (filled($chosen)) {
            $this->replyWithItem($group, $chosen);

            return;
        }

        $result = $this->matcher->resolve($text, $context + ['group_id' => $group->id]);

        // 比對器掛了（逾時、額度用盡、格式壞掉）—— 一律走人工，不要讓客人空等
        if (!filled($result)) {
            $this->replyWait($group, $text, $messageId);

            return;
        }

        $this->dispatchResult($group, $result, $context, $text, $messageId);
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

        if (!filled($group)) {
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
     * @param array         $context
     * @param string        $text
     * @param int|null      $messageId
     * @return void
     */
    private function dispatchResult(TelegramGroup $group, array $result, array $context, $text, $messageId)
    {
        $decision = $this->decide($result, $context);
        $actions = config('constants.AUTO_REPLY.DECISION');

        if ($decision['action'] === $actions['ANSWER']) {
            $this->replyWithItem($group, $decision['item']);

            return;
        }

        if ($decision['action'] === $actions['CLARIFY']) {
            $this->replyClarify($group, $decision['candidates']);

            return;
        }

        $this->replyWait($group, $text, $messageId);
    }

    /**
     * 決策：這個比對結果該怎麼回
     *
     * 只讀不寫 —— 送出訊息與更新狀態都由呼叫端負責，
     * 這樣 auto-reply:test 可以重用同一套判斷而不會有副作用，
     * 測出來的結果也才等於線上真正會做的事。
     *
     * @param array $result  比對器的輸出
     * @param array $context 反問脈絡；非空代表這已經是第二輪
     * @return array action（見 constants.AUTO_REPLY.DECISION）／item／candidates
     */
    private function decide(array $result, array $context)
    {
        $actions = config('constants.AUTO_REPLY.DECISION');
        $isHigh = $result['confidence'] === config('constants.AUTO_REPLY.CONFIDENCE.HIGH');

        if ($isHigh && filled($result['item_id'])) {
            $item = $this->quickReplyRepository->findActiveItem($result['item_id']);

            if (filled($item)) {
                return ['action' => $actions['ANSWER'], 'item' => $item, 'candidates' => []];
            }

            // 模型挑到的題目剛好被停用或刪掉，當作沒命中
            Log::warning('自動回覆命中的題目已不存在或已停用', ['item_id' => $result['item_id']]);
        }

        // 已經反問過一次就不再反問 —— 跟客人來回鬼打牆比直接轉人工更失禮
        $candidates = filled($context) ? [] : $this->takeCandidates($result);

        if (filled($candidates)) {
            return ['action' => $actions['CLARIFY'], 'item' => null, 'candidates' => $candidates];
        }

        return ['action' => $actions['WAIT'], 'item' => null, 'candidates' => []];
    }

    /**
     * 試跑一次比對，回傳決策與實際會送出的內容（不送訊息、不寫任何狀態）
     *
     * 供 auto-reply:test 調 prompt 與話術用。
     *
     * @param string $text
     * @param array  $candidateIds 模擬反問後的第二輪
     * @return array action／content／result
     */
    public function preview($text, array $candidateIds = [])
    {
        $context = filled($candidateIds) ? ['candidate_ids' => $candidateIds] : [];
        $result = $this->matcher->resolve($text, $context);
        $actions = config('constants.AUTO_REPLY.DECISION');

        if (!filled($result)) {
            return [
                'action'  => $actions['WAIT'],
                'content' => $this->previewContent($actions['WAIT'], null, []),
                'result'  => null,
            ];
        }

        $decision = $this->decide($result, $context);

        return [
            'action'  => $decision['action'],
            'content' => $this->previewContent($decision['action'], $decision['item'], $decision['candidates']),
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
     * @param array                           $candidates
     * @return string
     */
    private function previewContent($action, $item, array $candidates)
    {
        $actions = config('constants.AUTO_REPLY.DECISION');
        $signature = config('constants.AUTO_REPLY.SIGNATURE');

        if ($action === $actions['ANSWER']) {
            $template = $this->appSettingService->get(AppSettingService::KEY_TPL_ANSWER_FULL);
            $content = filled($template) ? strtr($template, ['{答案}' => $item->answer]) : '';
        } elseif ($action === $actions['CLARIFY']) {
            $options = [];
            $index = 1;
            foreach ($candidates as $candidate) {
                $options[] = "{$index}. {$candidate->label}";
                $index++;
            }

            $template = $this->appSettingService->get(AppSettingService::KEY_TPL_CLARIFY_FULL);
            $content = filled($template) ? strtr($template, ['{選項}' => implode("\n", $options)]) : '';
        } else {
            $content = (string) $this->appSettingService->get(AppSettingService::KEY_TPL_WAIT_FULL);
        }

        return filled($content) ? "{$content} {$signature}" : '';
    }

    /**
     * 送出題庫答案
     *
     * @param TelegramGroup                $group
     * @param \App\Models\QuickReplyItem   $item
     * @return void
     */
    private function replyWithItem(TelegramGroup $group, $item)
    {
        $content = $this->renderTemplate(
            $group,
            AppSettingService::KEY_TPL_ANSWER_FULL,
            AppSettingService::KEY_TPL_ANSWER_SHORT,
            ['{答案}' => $item->answer]
        );

        // 命中答案沒有冷卻 —— 客人重複問同一件事，就重複回答
        $this->send($group, $content, true);
        $this->telegramRepository->updateAutoReplyState($group, $item->id, null);
    }

    /**
     * 反問客人是哪一題
     *
     * @param TelegramGroup $group
     * @param array         $candidates QuickReplyItem 陣列
     * @return void
     */
    private function replyClarify(TelegramGroup $group, array $candidates)
    {
        $options = [];
        $ids = [];
        $index = 1;

        foreach ($candidates as $item) {
            $options[] = "{$index}. {$item->label}";
            $ids[] = $item->id;
            $index++;
        }

        $content = $this->renderTemplate(
            $group,
            AppSettingService::KEY_TPL_CLARIFY_FULL,
            AppSettingService::KEY_TPL_CLARIFY_SHORT,
            ['{選項}' => implode("\n", $options)]
        );

        // 反問不算回答，維持未回覆狀態讓既有的超時告警照常響
        $this->send($group, $content, false);
        $this->telegramRepository->updateAutoReplyState($group, null, implode(',', $ids));
    }

    /**
     * 說稍等，並把問題轉到內部支援群組
     *
     * @param TelegramGroup $group
     * @param string        $question
     * @param int|null      $messageId
     * @return void
     */
    private function replyWait(TelegramGroup $group, $question, $messageId)
    {
        // 才剛說過稍等就不要再說一次，顯得敷衍；求助單也已經開了
        if ($this->isWaitOnCooldown($group)) {
            $this->telegramRepository->clearAutoReplyPending($group);

            return;
        }

        $content = $this->renderTemplate(
            $group,
            AppSettingService::KEY_TPL_WAIT_FULL,
            AppSettingService::KEY_TPL_WAIT_SHORT,
            []
        );

        $this->send($group, $content, false);
        $this->telegramRepository->updateAutoReplyState($group, null, null);
        $this->supportService->openTicket($group, $question, $messageId);
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
        if (!filled($content)) {
            Log::warning('自動回覆話術模板為空，略過送出', ['group_id' => $group->id]);

            return;
        }

        try {
            $this->chatService->sendReply(
                $group->id,
                $content,
                null,
                config('constants.AUTO_REPLY.SENDER_NAME'),
                [
                    'mark_replied' => $markReplied,
                    'signature'    => config('constants.AUTO_REPLY.SIGNATURE'),
                    'is_auto'      => true,
                ]
            );
        } catch (\Exception $e) {
            Log::error('自動回覆送出失敗', ['group_id' => $group->id, 'error' => $e->getMessage()]);
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
        if (!filled($template)) {
            $template = $this->appSettingService->get($fullKey);
        }

        if (!filled($template)) {
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
        if (!filled($group->auto_reply_at)) {
            return true;
        }

        $minutes = (int) config('constants.AUTO_REPLY.GREETING_GAP_MINUTES');

        return $group->auto_reply_at->lt(now()->subMinutes($minutes));
    }

    /**
     * 「稍等」是否還在冷卻中
     *
     * 只有「上次也是回稍等」才算 —— auto_reply_item_id 有值代表上次是真的答了。
     *
     * @param TelegramGroup $group
     * @return bool
     */
    private function isWaitOnCooldown(TelegramGroup $group)
    {
        if (filled($group->auto_reply_item_id) || !filled($group->auto_reply_at)) {
            return false;
        }

        $minutes = (int) config('constants.AUTO_REPLY.WAIT_COOLDOWN_MINUTES');

        return $group->auto_reply_at->gt(now()->subMinutes($minutes));
    }

    /**
     * 取出反問的脈絡（還在等客人回答哪一個選項）
     *
     * @param TelegramGroup $group
     * @return array 有效時回 ['candidate_ids' => [...]]，否則空陣列
     */
    private function buildContext(TelegramGroup $group)
    {
        if (!filled($group->auto_reply_pending) || !filled($group->auto_reply_at)) {
            return [];
        }

        $minutes = (int) config('constants.AUTO_REPLY.PENDING_MINUTES');

        // 超過時限就當成全新問題
        if ($group->auto_reply_at->lt(now()->subMinutes($minutes))) {
            $this->telegramRepository->clearAutoReplyPending($group);

            return [];
        }

        $ids = array_values(array_filter(array_map('intval', explode(',', $group->auto_reply_pending))));

        return filled($ids) ? ['candidate_ids' => $ids] : [];
    }

    /**
     * 客人是不是直接回了選項編號
     *
     * @param string $text
     * @param array  $context
     * @return \App\Models\QuickReplyItem|null
     */
    private function matchNumericChoice($text, array $context)
    {
        if (!isset($context['candidate_ids'])) {
            return null;
        }

        $trimmed = trim($text);

        if (!preg_match('/^[1-9]\d?$/', $trimmed)) {
            return null;
        }

        $index = (int) $trimmed - 1;
        $ids = $context['candidate_ids'];

        if (!isset($ids[$index])) {
            return null;
        }

        return $this->quickReplyRepository->findActiveItem($ids[$index]);
    }

    /**
     * 取出候選題目（反問用）
     *
     * 只取還存在且啟用中的，數量以設定上限為準。
     *
     * @param array $result
     * @return array QuickReplyItem 陣列
     */
    private function takeCandidates(array $result)
    {
        $ids = isset($result['candidate_ids']) ? $result['candidate_ids'] : [];

        if (empty($ids)) {
            return [];
        }

        $max = (int) config('constants.AUTO_REPLY.CLARIFY_MAX_OPTIONS');

        // 一次撈完再依模型給的順序排 —— 最相符的要排在前面
        $found = $this->quickReplyRepository->getActiveItemsByIds($ids)->keyBy('id');
        $items = [];

        foreach ($ids as $id) {
            if (count($items) >= $max) {
                break;
            }

            if ($found->has($id)) {
                $items[] = $found->get($id);
            }
        }

        // 只剩一個候選就沒什麼好問的，讓它走人工比較快
        return count($items) >= 2 ? $items : [];
    }
}

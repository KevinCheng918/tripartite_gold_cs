<?php

namespace App\Services;

use App\Repositories\StationRepository;
use Illuminate\Support\Arr;

/**
 * 內部支援群組發訊服務
 *
 * 「取設定頁的 chat_id → 切到該 system 的 Bot → 送出」這三步，
 * 原本只長在 AutoReplySupportService 的 private 方法裡。站台餘點告警也需要
 * 同一條路（站台沒設自己的群組時要退到內部群組），所以抽出來共用。
 *
 * ⚠ 不要在別的地方再抄一份這段邏輯。
 * 設定的讀法一旦分成兩份，改了一邊忘了另一邊就會靜默送到錯的地方 ——
 * 這個專案已經為同一類問題修過兩次
 * （見 bugfix/2026-09-30-on-duty-reply-time、2026-09-14-multi-bot-media-download）。
 *
 * 注意 chat_id 是 **Telegram 的 chat id**，不是後台 telegram_group 表的主鍵，
 * 所以這裡走 TelegramBotService 直接送，不走 TelegramChatService::sendReply()。
 *
 * ## 話題分流（2026-10-06）
 *
 * 群組開了 Telegram 的話題功能之後，每個發訊點要宣告自己是**哪一類通知**
 * （`constants.SUPPORT_TOPIC.TYPES` 的 key），由設定頁的話題清單決定它進哪個話題。
 *
 * ⚠ **沒有任何話題勾到那一類 → 發到主區**，也就是沒設定之前的行為。
 * 設定還沒填的那段時間不該整個停擺。
 *
 * ⚠ **一類通知可以被勾在多個話題**（單向通知的情況），那時會逐話題各發一則，
 * 回傳的是**第一則**的結果。所以「要等客服引用回覆」的那幾類
 * （`needs_reply`）**只能勾一個話題** —— 不然 `ask_message_id` 只記得到一個，
 * 另一個話題的訊息會變成「回覆了也沒反應」。這條限制在設定頁驗證。
 */
class SupportGroupService
{
    private $botService;
    private $appSettingService;
    private $stationRepository;

    public function __construct(
        TelegramBotService $botService,
        AppSettingService $appSettingService,
        StationRepository $stationRepository
    ) {
        $this->botService = $botService;
        $this->appSettingService = $appSettingService;
        $this->stationRepository = $stationRepository;
    }

    /**
     * 內部支援群組是否已設定
     *
     * @return bool
     */
    public function isConfigured()
    {
        return filled($this->chatId());
    }

    /**
     * 內部支援群組的 Telegram chat_id
     *
     * @return string|null
     */
    public function chatId()
    {
        return $this->appSettingService->get(AppSettingService::KEY_SUPPORT_CHAT_ID);
    }

    /**
     * 發訊息到內部支援群組
     *
     * @param string      $text
     * @param array|null  $keyboard inline keyboard，沒有就傳 null
     * @param int|null    $replyTo  要引用的 Telegram message_id
     * @param string|null $type     通知類型（`constants.SUPPORT_TOPIC.TYPES` 的 key）
     * @return array|null Telegram API 的回傳（多話題時是第一則），未設定群組時為 null
     */
    public function send($text, $keyboard = null, $replyTo = null, $type = null)
    {
        $chatId = $this->chatId();

        if (blank($chatId)) {
            return null;
        }

        $this->switchBot();

        $first = null;

        foreach ($this->threadIdsFor($type) as $threadId) {
            $result = $this->botService->sendMessage($chatId, $text, $replyTo, $keyboard, $threadId);
            $first = filled($first) ? $first : $result;
        }

        return $first;
    }

    /**
     * 發圖片到內部支援群組
     *
     * `$photoUrl` 傳 `asset('storage/...')` 這種本站網址即可 ——
     * TelegramBotService 會自己換回本地檔案走 multipart 上傳，
     * 不會讓 Telegram 反過來抓我們的網址（內網時抓不到）。
     *
     * @param string      $photoUrl
     * @param string|null $caption 圖說。Telegram 上限 1024 字，超過會整則失敗
     * @param string|null $type    通知類型（`constants.SUPPORT_TOPIC.TYPES` 的 key）
     * @return array|null Telegram API 的回傳（多話題時是第一則），未設定群組時為 null
     */
    public function sendPhoto($photoUrl, $caption = null, $type = null)
    {
        $chatId = $this->chatId();

        if (blank($chatId)) {
            return null;
        }

        $this->switchBot();

        $first = null;

        foreach ($this->threadIdsFor($type) as $threadId) {
            $result = $this->botService->sendPhoto($chatId, $photoUrl, $caption, $threadId);
            $first = filled($first) ? $first : $result;
        }

        return $first;
    }

    /**
     * 直接發到某個話題（測試發送用）
     *
     * @param string   $text
     * @param int|null $threadId null 代表主區
     * @return array|null
     */
    public function sendToThread($text, $threadId = null)
    {
        $chatId = $this->chatId();

        if (blank($chatId)) {
            return null;
        }

        $this->switchBot();

        return $this->botService->sendMessage($chatId, $text, null, null, $threadId);
    }

    /**
     * 設定頁維護的話題清單
     *
     * @return array<int, array{name:string, thread_id:int, types:array}>
     */
    public function topics()
    {
        return $this->appSettingService->getJson(AppSettingService::KEY_SUPPORT_TOPICS, []);
    }

    /**
     * 這一類通知要發到哪幾個話題
     *
     * ⚠ 回傳 `[null]`（而不是空陣列）代表「發到主區」—— 呼叫端的 foreach
     * 才會跑一次。回空陣列會變成一則都不發，那是最糟的失敗方式：安靜消失。
     *
     * @param string|null $type
     * @return array<int, int|null>
     */
    private function threadIdsFor($type)
    {
        // 短路：沒宣告類型的呼叫端維持舊行為，不必去讀設定
        if (blank($type)) {
            return [null];
        }

        $threadIds = [];

        foreach ($this->topics() as $topic) {
            $types = (array) Arr::get($topic, 'types', []);
            $threadId = (int) Arr::get($topic, 'thread_id', 0);

            if ($threadId > 0 && in_array($type, $types, true)) {
                $threadIds[] = $threadId;
            }
        }

        return filled($threadIds) ? array_values(array_unique($threadIds)) : [null];
    }

    /**
     * 編輯支援群組裡的訊息（按鈕按完就地改成結果）
     *
     * @param int|null   $messageId
     * @param string     $text
     * @param array|null $keyboard
     * @return void
     */
    public function editMessage($messageId, $text, $keyboard = null)
    {
        $chatId = $this->chatId();

        if (blank($chatId) || blank($messageId)) {
            return;
        }

        $this->switchBot();
        $this->botService->editMessageText($chatId, $messageId, $text, $keyboard);
    }

    /**
     * 切換到支援群組所屬的 Bot
     *
     * 設定頁用下拉選 system，沒選就用 .env 的預設 bot。
     *
     * @return void
     */
    private function switchBot()
    {
        $systemId = $this->appSettingService->getInt(AppSettingService::KEY_SUPPORT_SYSTEM_ID);

        if ($systemId <= 0) {
            return;
        }

        $system = $this->stationRepository->getActiveSystems()->firstWhere('id', $systemId);

        if (filled($system) && filled($system->bot_token)) {
            $this->botService->setToken($system->bot_token);
        }
    }
}

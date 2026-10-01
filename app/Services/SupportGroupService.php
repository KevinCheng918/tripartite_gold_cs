<?php

namespace App\Services;

use App\Repositories\StationRepository;

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
     * @param string     $text
     * @param array|null $keyboard inline keyboard，沒有就傳 null
     * @param int|null   $replyTo  要引用的 Telegram message_id
     * @return array|null Telegram API 的回傳，未設定群組時為 null
     */
    public function send($text, $keyboard = null, $replyTo = null)
    {
        $chatId = $this->chatId();

        if (blank($chatId)) {
            return null;
        }

        $this->switchBot();

        return $this->botService->sendMessage($chatId, $text, $replyTo, $keyboard);
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
     * @return array|null Telegram API 的回傳，未設定群組時為 null
     */
    public function sendPhoto($photoUrl, $caption = null)
    {
        $chatId = $this->chatId();

        if (blank($chatId)) {
            return null;
        }

        $this->switchBot();

        return $this->botService->sendPhoto($chatId, $photoUrl, $caption);
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

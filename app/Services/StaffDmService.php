<?php

namespace App\Services;

use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 私訊內部同仁
 *
 * ⚠ **Telegram 不讓 bot 主動私訊沒對話過的人**（403 bot can't initiate
 * conversation with a user）。所以每個收件人都得先私訊 bot 一次，那一刻由
 * `TelegramChatService::bindPrivateChat()` 記下 `user.telegram_dm_ready`。
 *
 * ⚠ **判斷「可以私訊」要兩個欄位都成立**：`telegram_user_id`（知道發去哪）
 * 以及 `telegram_dm_ready`（他真的私訊過）。只看前者會對著一整批沒加過 bot
 * 的人狂發 403 —— 那個欄位從**群組訊息**也會被回填。
 *
 * 班表通知與每日提醒統計都走這裡，兩邊不各寫一份。
 */
class StaffDmService
{
    /** @var string 跳過原因：沒有設定收件人 */
    const SKIP_NO_RECIPIENT = 'no_recipient';

    /** @var string 跳過原因：收件人沒私訊過 bot */
    const SKIP_NOT_BOUND = 'not_bound';

    /** @var string 跳過原因：Telegram 拒收（多半是被封鎖） */
    const SKIP_SEND_FAILED = 'send_failed';

    private $userRepository;
    private $botService;

    public function __construct(UserRepository $userRepository, TelegramBotService $botService)
    {
        $this->userRepository = $userRepository;
        $this->botService = $botService;
    }

    /**
     * 發給一串帳號 id
     *
     * ⚠ **一個人失敗不能讓整批停掉**：逐人送、逐人判斷，沒收到的收進
     * `failed` 讓呼叫端回報。
     *
     * ⚠ `reason` 只在**完全沒送出任何一則**時才有值 —— 有人收到就不算失敗，
     * 不然三個人裡一個沒綁定會讓整件事看起來沒發生。
     *
     * @param array  $userIds
     * @param string $text
     * @return array sent（送出幾則）/ reason / failed（沒收到的暱稱）/ names（收到的暱稱）
     */
    public function sendToUserIds($userIds, $text)
    {
        $userIds = array_values(array_filter((array) $userIds));

        if (blank($userIds)) {
            return ['sent' => 0, 'reason' => self::SKIP_NO_RECIPIENT, 'failed' => [], 'names' => []];
        }

        $sent = 0;
        $failed = [];
        $names = [];
        $notBound = 0;

        foreach ($this->userRepository->getForDmByIds($userIds) as $user) {
            if (!$this->canDm($user)) {
                $failed[] = $user->nickname;
                $notBound++;

                continue;
            }

            if ($this->send($user, $text)) {
                $sent++;
                $names[] = $user->nickname;

                continue;
            }

            $failed[] = $user->nickname;
        }

        /*
         * 設定裡有 id、資料庫卻查不到（帳號被刪或被停用）——
         * 要讓它變成「沒收到的人」，不然那個人會從結果裡無聲消失。
         */
        $missing = count($userIds) - count($failed) - $sent;

        for ($i = 0; $i < $missing; $i++) {
            $failed[] = '#?';
        }

        if ($sent > 0) {
            return ['sent' => $sent, 'reason' => null, 'failed' => $failed, 'names' => $names];
        }

        // 一則都沒送出：分得出「全都沒綁定」和「送出時被拒」對處理方式有差
        return [
            'sent'   => 0,
            'reason' => $notBound === count($userIds) ? self::SKIP_NOT_BOUND : self::SKIP_SEND_FAILED,
            'failed' => $failed,
            'names'  => [],
        ];
    }

    /**
     * 發給已經查出來的帳號物件
     *
     * ⚠ 這個 `$user` 必須是 `UserRepository::findForDm()` / `getForDmByIds()`
     * 查出來的 —— 別處的查詢 select 裡沒有 `telegram_dm_ready`，
     * 讀到的永遠是 null，於是**每個人都被判定成沒綁定**而且不會報錯。
     *
     * @param object $user
     * @param string $text
     * @return bool
     */
    public function send($user, $text)
    {
        if (blank($user) || !$this->canDm($user)) {
            return false;
        }

        try {
            $result = $this->botService->sendMessage($user->telegram_user_id, $text);

            if (filled(Arr::get((array) $result, 'result'))) {
                return true;
            }

            Log::warning('私訊同仁未送達', ['user_id' => $user->id, 'response' => $result]);

            return false;
        } catch (\Exception $e) {
            // 失敗不丟例外：一個人發不出去不能讓整輪停掉
            Log::error('私訊同仁失敗', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * 這個人可以被私訊嗎
     *
     * @param object $user
     * @return bool
     */
    public function canDm($user)
    {
        return filled($user->telegram_user_id) && (bool) $user->telegram_dm_ready;
    }
}

<?php

namespace App\Services;

use App\Repositories\UserNoticeMuteRepository;
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

    /**
     * 圖片說明文字的長度上限
     *
     * Telegram 的 caption 上限就是 1024 字元，超過整則會被拒收。
     * 超過時寧可不附圖、改發純文字 —— 訊息送得出去比有沒有圖重要。
     *
     * @var int
     */
    const CAPTION_LIMIT = 1024;

    private $userRepository;
    private $botService;
    private $muteRepository;

    /**
     * 每種通知的靜音名單，查過就留著
     *
     * 發送端是「每位同仁跑一輪」的迴圈，不快取就是 N+1。
     * 排程每跑一輪是一個新的 Service 實例，所以不會拿到隔夜的舊資料。
     *
     * @var array notice_key => user_id 陣列
     */
    private $mutedCache = [];

    public function __construct(
        UserRepository $userRepository,
        TelegramBotService $botService,
        UserNoticeMuteRepository $muteRepository
    ) {
        $this->userRepository = $userRepository;
        $this->botService = $botService;
        // 帳號管理可以逐個帳號關掉某幾種個人版通知
        $this->muteRepository = $muteRepository;
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
     * @param array       $userIds
     * @param string      $text
     * @param string|null $imagePath 要附的圖（public disk 的相對路徑）；null 就只發文字
     * @return array sent（送出幾則）/ reason / failed（沒收到的暱稱）/ names（收到的暱稱）
     */
    public function sendToUserIds($userIds, $text, $imagePath = null)
    {
        $userIds = array_values(array_filter((array) $userIds));

        if (blank($userIds)) {
            return ['sent' => 0, 'reason' => self::SKIP_NO_RECIPIENT, 'failed' => [], 'names' => []];
        }

        /*
         * ⚠ **管理者不在收件人之列**（需求方 2026-10-06）。
         *
         * 設定頁的候選清單已經排除管理者（`getDmCandidates()`），但**早先存進去的
         * 設定值可能還留著管理者的 id** —— 那種情況下 UI 看不到他、無從取消勾選，
         * 他卻還是會一直收到。所以送出這一端也要擋。
         */
        $sent = 0;
        $failed = [];
        $names = [];
        $notBound = 0;
        $admin = (int) config('constants.USER.LEVEL.ADMIN');
        $eligible = 0;

        foreach ($this->userRepository->getForDmByIds($userIds) as $user) {
            if ((int) $user->level === $admin) {
                continue;
            }

            $eligible++;

            if (!$this->canDm($user)) {
                $failed[] = $user->nickname;
                $notBound++;

                continue;
            }

            if ($this->send($user, $text, null, $imagePath)) {
                $sent++;
                $names[] = $user->nickname;

                continue;
            }

            $failed[] = $user->nickname;
        }

        /*
         * 設定裡有 id、資料庫卻查不到（帳號被刪或被停用）——
         * 要讓它變成「沒收到的人」，不然那個人會從結果裡無聲消失。
         *
         * ⚠ 基準是 `$eligible`（查得到且不是管理者的人數）而不是設定裡的 id 數：
         * 用後者的話，被刻意排除的管理者會被算成「沒收到」而出現在失敗名單裡。
         */
        $missing = $eligible - count($failed) - $sent;

        for ($i = 0; $i < $missing; $i++) {
            $failed[] = '#?';
        }

        if ($sent > 0) {
            return ['sent' => $sent, 'reason' => null, 'failed' => $failed, 'names' => $names];
        }

        /*
         * 一則都沒送出。分得出三種：
         *   勾的全是管理者（已被排除）→ 等於沒有收件人
         *   收件人全都沒綁定            → 要請他們私訊機器人
         *   送出時被拒                  → 多半是被封鎖，要查 log
         */
        if ($eligible < 1) {
            return ['sent' => 0, 'reason' => self::SKIP_NO_RECIPIENT, 'failed' => $failed, 'names' => []];
        }

        return [
            'sent'   => 0,
            'reason' => $notBound === $eligible ? self::SKIP_NOT_BOUND : self::SKIP_SEND_FAILED,
            'failed' => $failed,
            'names'  => [],
        ];
    }

    /**
     * 發給已經查出來的帳號物件
     *
     * ⚠ 這個 `$user` 必須是 `UserRepository::getForDmByIds()` /
     * `getDmCandidates()` 查出來的 —— 別處的查詢 select 裡沒有 `telegram_dm_ready`，
     * 讀到的永遠是 null，於是**每個人都被判定成沒綁定**而且不會報錯。
     *
     * ⚠ **`$noticeKey` 只有「個人版」才傳**（需求方 2026-10-12）。帳號管理那邊
     * 關掉的是「我自己那一份」，主管被勾進完整版名單的那份照發 ——
     * 所以 `sendToUserIds()` 轉呼叫這裡時刻意不帶 key。
     *
     * ⚠ 新增一種個人版通知時**記得傳 key**。沒傳就是不受管，而且不會報錯 ——
     * 那是為了讓完整版走同一支而留的洞，代價是新通知可能忘記納管。
     *
     * @param object      $user
     * @param string      $text
     * @param string|null $noticeKey constants.NOTICE.PERSONAL 的值；null＝不受靜音設定管
     * @param string|null $imagePath 要附的圖（public disk 的相對路徑）；null 就只發文字
     * @return bool
     */
    public function send($user, $text, $noticeKey = null, $imagePath = null)
    {
        if (blank($user) || !$this->canDm($user)) {
            return false;
        }

        /*
         * 他關掉了這一種就當作沒這回事，回 true。
         *
         * ⚠ 這會讓呼叫端的 `sent` 多算一 —— Command 印的「發送 5 則」其實是
         * 「處理了 5 個收件人」。**是刻意選的**：另一邊（回 false）會把他算進
         * `failed`（「沒收到的人」），而那份名單的用途是讓人去追「誰該收卻沒收到」。
         * 自己關掉的人出現在那裡，主管會跑去問他是不是沒綁定機器人 ——
         * 數字小小失真，換掉一個會造成錯誤行動的誤導。
         *
         * 要查「為什麼他沒收到」直接看 `user_notice_mute` 表；
         * 這裡不寫 log，不然每天每人一筆會把日誌洗掉。
         */
        if ($this->isMuted($user->id, $noticeKey)) {
            return true;
        }

        try {
            /*
             * 有圖就走 sendPhoto，文字當 caption。
             *
             * ⚠ **caption 上限 1024 字元**，超過 Telegram 會整則拒收 ——
             * 所以太長的文字寧可不附圖、改發純文字，也不要訊息發不出去。
             * `sendPhoto()` 自己還會處理「截圖太長不符 photo 規格」那一種，
             * 改以檔案送出。
             */
            $withPhoto = filled($imagePath) && mb_strlen($text) <= self::CAPTION_LIMIT;

            if (filled($imagePath) && !$withPhoto) {
                Log::info('私訊內容超過 caption 上限，改發純文字不附圖', [
                    'user_id' => $user->id,
                    'length'  => mb_strlen($text),
                ]);
            }

            $result = $withPhoto
                ? $this->botService->sendPhoto($user->telegram_user_id, $imagePath, $text)
                : $this->botService->sendMessage($user->telegram_user_id, $text);

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

    /**
     * 這個人關掉了這一種通知嗎
     *
     * 同一個 key 只查一次 DB，之後走實例內的快取 —— 發送端是逐人迴圈。
     *
     * @param int         $userId
     * @param string|null $noticeKey null＝完整版，不受管
     * @return bool
     */
    private function isMuted($userId, $noticeKey)
    {
        if (blank($noticeKey)) {
            return false;
        }

        if (!Arr::has($this->mutedCache, $noticeKey)) {
            $this->mutedCache[$noticeKey] = $this->muteRepository->getMutedUserIds($noticeKey);
        }

        return in_array((int) $userId, $this->mutedCache[$noticeKey], true);
    }
}

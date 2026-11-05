<?php

namespace App\Services;

use App\Presenters\UserAgentPresenter;
use App\Repositories\LoginLogRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 登入通知：把登入紀錄私訊給帳號本人
 *
 * 需求方 2026-11-05：「登入後台要發送私人通知，幾點幾分 IP 多少登入、
 * 作業系統、瀏覽器、裝置。」
 *
 * 裁示（2026-11-05／06）：
 *
 * | 題目 | 裁示 |
 * |---|---|
 * | 發送時機 | 每分鐘排程，不在登入當下同步發 |
 * | 成功登入 | **每次都發** |
 * | 失敗登入 | **沒見過的 IP 或裝置才發** |
 * | 裝置欄位 | 桌機／手機／平板 |
 * | 可否關閉 | **不可**（安全通知可以關等於留後門）|
 *
 * ⚠ **為什麼不在 `LoginController` 裡直接發**：`TelegramBotService` 的
 * HTTP client 是 `['timeout' => 10]`，而本專案 `QUEUE_CONNECTION=sync`
 * （`dispatch()` 當場跑完、不進背景）。在登入流程裡發私訊的話，Telegram
 * 慢或掛掉時使用者按下登入會卡最多 10 秒。登入是所有人每天第一個動作。
 *
 * ⚠⚠ **沒私訊過機器人的帳號收不到，而且不會報錯**：`StaffDmService::canDm()`
 * 要 `telegram_user_id` 與 `telegram_dm_ready` 兩個都成立（Telegram 不讓 bot
 * 主動私訊沒對話過的人）。對一般通知沒差，但這是**安全通知** ——
 * 以為自己被保護著、其實沒有，比沒有這個功能更糟。
 * 所以每輪會把「該收卻收不到」的人數記一筆 log（見 `logUnreachable()`）。
 */
class LoginNoticeService
{
    private $loginLogRepository;
    private $userRepository;
    private $staffDmService;

    public function __construct(
        LoginLogRepository $loginLogRepository,
        UserRepository $userRepository,
        StaffDmService $staffDmService
    ) {
        $this->loginLogRepository = $loginLogRepository;
        $this->userRepository = $userRepository;
        $this->staffDmService = $staffDmService;
    }

    /**
     * 跑一輪：撈未通知的登入紀錄，該發的發、該跳過的標記掉
     *
     * @param bool $dryRun 只組字不發送，也不標記
     * @return array{sent: int, skipped: int, stale: int, unreachable: int, pending: int, messages: array<int, string>}
     */
    public function run($dryRun = false)
    {
        $empty = [
            'sent'        => 0,
            'skipped'     => 0,
            'stale'       => 0,
            'unreachable' => 0,
            'pending'     => 0,
            'messages'    => [],
        ];

        // 短路：開關關掉時一次 DB 都不查
        if (!config('constants.LOGIN_NOTICE.ENABLED')) {
            return $empty;
        }

        $lookback = (int) config('constants.LOGIN_NOTICE.LOOKBACK_MINUTES');
        $since = now()->subMinutes($lookback);

        $logs = $this->loginLogRepository->pendingNotices(
            $since,
            (int) config('constants.LOGIN_NOTICE.BATCH')
        );

        // 窗口外的舊紀錄照樣要標記掉，所以這一步不能因為 $logs 是空的就跳過
        $staleIds = $this->loginLogRepository->staleNoticeIds($since);

        if (blank($logs) && blank($staleIds)) {
            return $empty;
        }

        $users = $this->recipientsFor($logs);
        $result = $this->dispatchAll($logs, $users, $dryRun);

        if (!$dryRun) {
            $this->loginLogRepository->markNotified($staleIds);
        }

        $result['stale'] = count($staleIds);

        return $result;
    }

    /**
     * 整批取收件人，避免逐筆查帳號造成 N+1
     *
     * ⚠ `user_id` 是 null 的紀錄（帳號不存在時的失敗嘗試）沒有收件人 ——
     * `array_filter` 會濾掉，後面靠 `Arr::get($users, ...)` 取不到就跳過。
     *
     * @param \Illuminate\Support\Collection $logs
     * @return array<int, object> user_id => user
     */
    private function recipientsFor($logs)
    {
        $ids = array_filter(array_unique($logs->pluck('user_id')->all()));

        if (blank($ids)) {
            return [];
        }

        return $this->userRepository->getForDmByIds($ids)->keyBy('id')->all();
    }

    /**
     * 逐筆判斷要不要發，並回統計
     *
     * @param \Illuminate\Support\Collection $logs
     * @param array<int, object>             $users
     * @param bool                           $dryRun
     * @return array{sent: int, skipped: int, stale: int, unreachable: int, pending: int, messages: array<int, string>}
     */
    private function dispatchAll($logs, array $users, $dryRun)
    {
        $sent = 0;
        $skipped = 0;
        $unreachable = 0;
        $messages = [];
        $handledIds = [];
        $pendingIds = [];
        // 同一輪裡同一個帳號的「見過的來源」只查一次 DB
        $knownCache = [];

        foreach ($logs as $log) {
            $user = Arr::get($users, (int) $log->user_id);

            // 帳號不存在、或帳號已經被刪掉 —— 沒有人可以通知，標記掉就好
            if (blank($user)) {
                $skipped++;
                $handledIds[] = $log->id;

                continue;
            }

            $text = $this->composeFor($log, $user, $knownCache);

            /*
             * 回 null 代表「現在還不發，但也還不確定不發」——
             * 只有「已知來源的失敗、累積還沒滿、窗口還沒過」會這樣。
             * 這些**不標記**，留給下一輪重新判斷；真的等到窗口過了，
             * 會被 `staleNoticeIds()` 收掉。
             */
            if (blank($text)) {
                $pendingIds[] = $log->id;

                continue;
            }

            $handledIds[] = $log->id;

            if ($dryRun) {
                $messages[] = $text;
                $sent++;

                continue;
            }

            if (!$this->staffDmService->canDm($user)) {
                $unreachable++;
                $skipped++;

                continue;
            }

            /*
             * ⚠ **刻意不傳 `$noticeKey`** —— 不納入可靜音清單（需求方裁示）。
             * 帳號被入侵時，入侵者第一件事就能把這個通知關掉。
             */
            if ($this->staffDmService->send($user, $text)) {
                $sent++;

                continue;
            }

            $skipped++;
        }

        if (!$dryRun) {
            $this->loginLogRepository->markNotified($handledIds);
            $this->logUnreachable($unreachable);
        }

        return [
            'sent'        => $sent,
            'skipped'     => $skipped,
            'stale'       => 0,
            'unreachable' => $unreachable,
            'messages'    => $messages,
            // 「這一輪先不發、下一輪再判斷」的筆數，Command 會印出來
            'pending'     => count($pendingIds),
        ];
    }

    /**
     * 組這一筆要發的訊息；回 null 代表這一輪先不發
     *
     * @param object                $log
     * @param object                $user
     * @param array<string, array>  $knownCache 同一輪的「見過的來源」快取
     * @return string|null
     */
    private function composeFor($log, $user, array &$knownCache)
    {
        $isNewSource = $this->isNewSource($log, $knownCache);

        if ((bool) $log->is_success) {
            return $this->successText($log, $isNewSource);
        }

        // 沒見過的來源失敗 —— 第 1 次就發，不等累積（需求方裁示）
        if ($isNewSource) {
            return $this->render('FAIL_NEW_SOURCE', $log, [
                '{reason}' => (string) $log->fail_reason,
            ]);
        }

        return $this->repeatedFailText($log);
    }

    /**
     * 成功登入的訊息
     *
     * ⚠ 沒見過的來源**不另外發一則**，而是在同一則後面加一行 ——
     * 成功登入已經每次都發了，再多一則「而且是新來源」會變成同一件事兩則訊息。
     *
     * @param object $log
     * @param bool   $isNewSource
     * @return string
     */
    private function successText($log, $isNewSource)
    {
        $text = $this->render('SUCCESS', $log);

        if (!$isNewSource) {
            return $text;
        }

        $suffix = (string) config('constants.LOGIN_NOTICE.SUCCESS_NEW_SOURCE');

        return "{$text}{$suffix}";
    }

    /**
     * 已知來源的連續失敗：滿門檻才發，否則回 null 等下一輪
     *
     * ⚠ 自己打錯一次密碼就被私訊的話，這個通知很快就會被當成雜訊 ——
     * 所以已知來源要累積滿 `FAIL_THRESHOLD` 次才發。
     *
     * @param object $log
     * @return string|null
     */
    private function repeatedFailText($log)
    {
        $windowMinutes = (int) config('constants.LOGIN_NOTICE.FAIL_WINDOW_MINUTES');
        $threshold = (int) config('constants.LOGIN_NOTICE.FAIL_THRESHOLD');

        $count = $this->loginLogRepository->countFailuresSince(
            $log->account,
            $log->created_at->copy()->subMinutes($windowMinutes)
        );

        if ($count < $threshold) {
            return null;
        }

        return $this->render('FAIL_REPEATED', $log, [
            '{minutes}' => (string) $windowMinutes,
            '{count}'   => (string) $count,
        ]);
    }

    /**
     * 這筆登入的 IP 或裝置，以前沒見過嗎
     *
     * ⚠⚠ **裝置比的是指紋，不是 User-Agent 原字串。** Chrome 每次小版本更新
     * UA 就變了，拿原字串比的話每個人每隔幾天就收到一則「新裝置登入」——
     * 通知一吵就會被忽略。指紋只取名稱不含版本號，見
     * `UserAgentPresenter::signature()`。
     *
     * ⚠ 「見過」只採計**成功登入**的歷史（見 `LoginLogRepository::knownIps()`
     * 的說明）—— 否則攻擊者第一次失敗之後他的 IP 就變成「見過」了。
     *
     * @param object               $log
     * @param array<string, array> $knownCache
     * @return bool
     */
    private function isNewSource($log, array &$knownCache)
    {
        $userId = (int) $log->user_id;
        $cacheKey = (string) $userId;

        if (!Arr::has($knownCache, $cacheKey)) {
            $limit = (int) config('constants.LOGIN_NOTICE.KNOWN_LIMIT');
            $devices = $this->loginLogRepository->knownDevices($userId, $limit);

            $signatures = [];
            foreach ($devices as $device) {
                $signatures[] = UserAgentPresenter::signature($device);
            }

            $knownCache[$cacheKey] = [
                'ips'        => $this->loginLogRepository->knownIps($userId, $limit),
                'signatures' => array_unique($signatures),
            ];
        }

        $known = Arr::get($knownCache, $cacheKey);

        /*
         * ⚠ **完全沒有成功登入歷史時不算「新來源」**（回 false）。
         * 不然第一次登入的新人會收到一則「這是我們沒有見過的登入來源」——
         * 技術上正確，但對他毫無意義，而且是他自己剛設定好帳號的那一刻。
         */
        if (blank(Arr::get($known, 'ips')) && blank(Arr::get($known, 'signatures'))) {
            return false;
        }

        if (!in_array((string) $log->ip, (array) Arr::get($known, 'ips'), true)) {
            return true;
        }

        $signature = UserAgentPresenter::signature($log->device);

        return !in_array($signature, (array) Arr::get($known, 'signatures'), true);
    }

    /**
     * 套文案模板
     *
     * @param string               $key   `constants.LOGIN_NOTICE` 底下的 key
     * @param object               $log
     * @param array<string, string> $extra 額外的佔位代換
     * @return string
     */
    private function render($key, $log, array $extra = [])
    {
        $parsed = UserAgentPresenter::parse($log->device);

        $replacements = array_merge([
            '{time}'    => $log->created_at->format('Y-m-d H:i'),
            '{ip}'      => (string) $log->ip,
            '{os}'      => (string) Arr::get($parsed, 'os'),
            '{browser}' => (string) Arr::get($parsed, 'browser'),
            '{device}'  => (string) Arr::get($parsed, 'device'),
        ], $extra);

        return strtr((string) config("constants.LOGIN_NOTICE.{$key}"), $replacements);
    }

    /**
     * 記一筆「該收卻收不到的人數」
     *
     * ⚠ **記數量不記逐人**：每分鐘每人一筆會把日誌洗掉。
     * 要查是誰看帳號管理列表的「有沒有私訊過機器人」那一欄。
     *
     * ⚠ 這個數字長期不是 0 就代表有人以為自己被保護著、其實沒有 ——
     * 不記的話永遠不會發現。
     *
     * @param int $unreachable
     * @return void
     */
    private function logUnreachable($unreachable)
    {
        if ($unreachable < 1) {
            return;
        }

        Log::warning('登入通知有人收不到（未綁定或沒私訊過機器人）', [
            'count' => $unreachable,
        ]);
    }
}

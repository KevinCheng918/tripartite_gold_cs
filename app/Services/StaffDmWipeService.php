<?php

namespace App\Services;

use App\Repositories\StaffDmMessageRepository;
use App\Repositories\StaffDmWipeLogRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 清除機器人與同仁的私訊
 *
 * 兩個入口，同一套核心：
 *   - 後台按鈕 → 清某一位同仁**目前所有**可清的私訊（離職、手機遺失時用）
 *   - 排程     → 清**所有人**超過保留時數的私訊（預設關）
 *
 * ⚠⚠ **48 小時是 Telegram 的硬限制**。超過就永久刪不掉，後台做什麼都沒用。
 * 所以這支服務回傳的計數裡 `expired` 和 `cleared` 一樣重要 ——
 * 介面必須把它顯示出來，否則使用者會以為清乾淨了。
 *
 * ⚠⚠ **清掉私訊等於清掉通知本身**。逾時升級那則私訊是唯一告訴人「哪張單
 * 逾時了」的東西（電話只會響、沒有內容）。所以自動清除預設關，而保留時數
 * 的預設值刻意給到 12 小時。
 *
 * 詳見 .claude/knowledge/features/telegram-dm-wipe.md
 */
class StaffDmWipeService
{
    /** @var int 設定頁顯示幾筆稽核紀錄 */
    const RECENT_LOG_LIMIT = 20;

    /** @var string 自動清除的操作者名稱（沒有真人） */
    const AUTO_OPERATOR_NAME = '系統（自動清除）';

    private $messageRepository;
    private $logRepository;
    private $userRepository;
    private $botService;
    private $appSettingService;

    /**
     * 清除對象的名單，查過就留著
     *
     * 一次手動清除會問到它兩次（找對象、寫稽核的名稱快照），設定頁還會再
     * 問一次。比照 `StaffDmService::$mutedCache`：一個請求／一輪排程是一個
     * 新的 Service 實例，所以不會拿到隔夜的舊資料。
     *
     * @var \Illuminate\Database\Eloquent\Collection|null
     */
    private $candidateCache = null;

    public function __construct(
        StaffDmMessageRepository $messageRepository,
        StaffDmWipeLogRepository $logRepository,
        UserRepository $userRepository,
        TelegramBotService $botService,
        AppSettingService $appSettingService
    ) {
        $this->messageRepository = $messageRepository;
        $this->logRepository = $logRepository;
        $this->userRepository = $userRepository;
        $this->botService = $botService;
        $this->appSettingService = $appSettingService;
    }

    /**
     * 後台手動清除某一位同仁的私訊
     *
     * ⚠ 手動清除**不套用保留時數**：按這個按鈕的情境是「這個人離職了」或
     * 「他手機不見了」—— 那時候「保留最近 12 小時」完全是反效果。
     *
     * @param int    $userId
     * @param object $operator 操作者（要有 id 與 nickname）
     * @return array{ok: bool, cleared: int, expired: int, failed: int, message: string}
     */
    public function wipeForUser($userId, $operator)
    {
        $target = $this->candidates()->firstWhere('id', (int) $userId);

        if (blank($target)) {
            return $this->failure(trans('notification.msg.dm_wipe_no_user'));
        }

        $counts = $this->wipe((int) $userId, now(), $operator, (int) config('constants.STAFF_DM_WIPE.SOURCE.MANUAL'));

        return [
            'ok'      => true,
            'cleared' => (int) Arr::get($counts, 'cleared'),
            'expired' => (int) Arr::get($counts, 'expired'),
            'failed'  => (int) Arr::get($counts, 'failed'),
            'message' => $this->resultMessage($counts, $target->nickname),
        ];
    }

    /**
     * 排程自動清除（所有人）
     *
     * @return array{skipped: bool, cleared: int, expired: int, failed: int}
     */
    public function autoWipe()
    {
        /*
         * ⚠ 開關判斷在最前面，而且**先於任何 DB 查詢** ——
         * 這支排程每 10 分鐘跑一次，關著的時候不該碰資料庫（PROMPTS.md 的
         * short-circuit 規則）。
         */
        if (!$this->appSettingService->getBool(AppSettingService::KEY_DM_WIPE_AUTO_ENABLED)) {
            return ['skipped' => true, 'cleared' => 0, 'expired' => 0, 'failed' => 0];
        }

        $before = now()->subHours($this->keepHours());
        $counts = $this->wipe(null, $before, null, (int) config('constants.STAFF_DM_WIPE.SOURCE.AUTO'));

        // 過了窗口的紀錄已經永久刪不掉，留著只是佔空間（但要留夠久讓介面顯示「已過期幾則」）
        $this->messageRepository->purgeOlderThan(
            now()->subDays((int) config('constants.STAFF_DM_WIPE.LOG_KEEP_DAYS'))
        );

        return [
            'skipped' => false,
            'cleared' => (int) Arr::get($counts, 'cleared'),
            'expired' => (int) Arr::get($counts, 'expired'),
            'failed'  => (int) Arr::get($counts, 'failed'),
        ];
    }

    /**
     * 清除的核心
     *
     * @param int|null    $userId null＝所有人
     * @param Carbon      $before 只清這個時間之前送出的
     * @param object|null $operator 手動時的操作者；自動時為 null
     * @param int         $source 見 constants.STAFF_DM_WIPE.SOURCE
     * @return array{cleared: int, expired: int, failed: int}
     */
    private function wipe($userId, Carbon $before, $operator, $source)
    {
        $windowStart = $this->windowStart();

        /*
         * ⚠ 先把窗口外的標記掉，再撈要清的。
         *
         * 不做這一步的話，那些永遠清不掉的 message_id 會留在「待處理」裡，
         * **每一輪排程都去撈一次**，而且計數永遠對不上。
         *
         * ⚠ 回傳值（標記了幾筆）**刻意不當成計數用**：它是全表的數字，
         * 塞進某一位同仁的稽核列會記出一個看起來很嚴重、卻跟那個人無關的數。
         */
        $this->messageRepository->markExpiredBefore($windowStart);

        $limit = (int) config('constants.STAFF_DM_WIPE.BATCH_SIZE') * 10;

        $targets = $this->messageRepository->getWipeTargets($userId, $before, $windowStart, $limit);

        // 沒有靜默上限：這一輪撈滿了就講出來，否則看起來像「全部清完了」
        if ($targets->count() >= $limit) {
            Log::info('私訊清除達到單輪上限，剩下的下一輪處理', ['limit' => $limit]);
        }

        $perUser = $this->deleteTargets($targets);

        // 「永久刪不掉幾則」逐人從資料算（理由同上）
        $expiredByUser = $this->messageRepository->countByUser($windowStart);

        $this->writeLogs($perUser, $expiredByUser, $operator, $source, $userId);

        return $this->totals($perUser, $expiredByUser, $userId);
    }

    /**
     * 把各人的計數加總成這一輪的結果
     *
     * @param array    $perUser
     * @param array    $expiredByUser
     * @param int|null $userId 單一對象時只回他的
     * @return array{cleared: int, expired: int, failed: int}
     */
    private function totals(array $perUser, array $expiredByUser, $userId)
    {
        if (filled($userId)) {
            $own = (array) Arr::get($perUser, (int) $userId, []);

            return [
                'cleared' => (int) Arr::get($own, 'cleared'),
                'expired' => (int) Arr::get($expiredByUser, (int) $userId . '.expired'),
                'failed'  => (int) Arr::get($own, 'failed'),
            ];
        }

        $cleared = 0;
        $failed = 0;

        foreach ($perUser as $own) {
            $cleared += (int) Arr::get($own, 'cleared');
            $failed += (int) Arr::get($own, 'failed');
        }

        $expired = 0;

        foreach ($expiredByUser as $own) {
            $expired += (int) Arr::get($own, 'expired');
        }

        return ['cleared' => $cleared, 'expired' => $expired, 'failed' => $failed];
    }

    /**
     * 把撈出來的私訊送去 Telegram 刪掉
     *
     * ⚠ **依 chat_id 分組再切批**：`deleteMessages` 一次只能處理同一個
     * 聊天室，而自動清除撈的是所有人的。
     *
     * ⚠ **失敗刻意不標記結果**（維持 null）：留著下一輪會再試，等它過了
     * 48 小時就會被掃成 EXPIRED。標成失敗會讓一次暫時性的網路錯誤
     * 變成永久放棄。
     *
     * @param \Illuminate\Support\Collection $targets
     * @return array<int, array{cleared: int, failed: int}> user_id => 計數（帳號已刪除者為 0）
     */
    private function deleteTargets($targets)
    {
        $perUser = [];
        $batchSize = (int) config('constants.STAFF_DM_WIPE.BATCH_SIZE');
        $cleared = (int) config('constants.STAFF_DM_WIPE.RESULT.CLEARED');

        foreach ($targets->groupBy('telegram_chat_id') as $chatId => $rows) {
            foreach ($rows->chunk($batchSize) as $chunk) {
                $ids = $chunk->pluck('telegram_message_id')->all();
                $outcome = $this->botService->deleteMessages($chatId, $ids);
                $ok = (bool) Arr::get($outcome, 'ok');

                if (!$ok) {
                    Log::warning('私訊清除失敗（維持未處理，下一輪再試）', [
                        'chat_id'     => $chatId,
                        'count'       => count($ids),
                        'description' => Arr::get($outcome, 'description'),
                    ]);
                }

                /*
                 * ⚠ 成功才標記。失敗維持 null ⇒ 下一輪再試，
                 * 等它過了 48 小時會被掃成 EXPIRED。標成失敗會讓一次
                 * 暫時性的網路錯誤變成永久放棄。
                 *
                 * ⚠ 標成「已清除」而不是「已刪除」：Telegram 對刪不掉的訊息是
                 * **靜默跳過並回傳成功**，所以這裡面可能混著同仁自己早就刪掉的。
                 * 兩者的結果一樣（不在他的 Telegram 裡了），不必分。
                 */
                if ($ok) {
                    $this->messageRepository->markResult($chunk->pluck('id')->all(), $cleared);
                }

                // ⚠ 逐人累計，不要只回總數 —— 稽核紀錄要答得出「誰的被清了幾則」
                foreach ($chunk->groupBy('user_id') as $userId => $own) {
                    $perUser = $this->addCount($perUser, (int) $userId, $ok ? 'cleared' : 'failed', $own->count());
                }
            }
        }

        return $perUser;
    }

    /**
     * 累加某人的某一種計數
     *
     * @param array  $perUser
     * @param int    $userId
     * @param string $field cleared | failed
     * @param int    $delta
     * @return array
     */
    private function addCount(array $perUser, $userId, $field, $delta)
    {
        $own = (array) Arr::get($perUser, $userId, ['cleared' => 0, 'failed' => 0]);
        $own[$field] = (int) Arr::get($own, $field) + (int) $delta;

        $perUser[$userId] = $own;

        return $perUser;
    }

    /**
     * 寫稽核紀錄
     *
     * ⚠⚠ **這一步不能省**。一個能清掉自己通知紀錄的按鈕，正是內部人員要
     * 掩蓋「我沒處理那張單」時會用的東西 —— 沒有稽核，這個功能是在幫人
     * 消滅證據。
     *
     * ⚠ **一位同仁一列**：自動清除掃的是所有人，寫成一列的話就答不出
     * 「誰的被清了」。
     *
     * ⚠ 操作者與對象都留**名稱快照**：帳號刪掉之後（離職正是會用到這個功能
     * 的時機），只靠外鍵會變成兩個 null。
     *
     * @param array       $perUser       user_id => cleared/failed
     * @param array       $expiredByUser user_id => pending/expired
     * @param object|null $operator
     * @param int         $source
     * @param int|null    $userId 手動時的對象（即使 0 則也要留紀錄）
     * @return void
     */
    private function writeLogs(array $perUser, array $expiredByUser, $operator, $source, $userId)
    {
        $names = $this->targetNames(array_keys($perUser), $userId);

        if (blank($names)) {
            return;
        }

        $operatorId = filled($operator) ? $operator->id : null;
        $operatorName = filled($operator) ? (string) $operator->nickname : self::AUTO_OPERATOR_NAME;

        foreach ($names as $targetId => $nickname) {
            $own = (array) Arr::get($perUser, $targetId, []);

            $this->logRepository->create([
                'operator_user_id' => $operatorId,
                'operator_name'    => $operatorName,
                // 帳號已刪除的那些 message 其 user_id 是 null ⇒ 聚合在 key 0，外鍵要寫回 null
                'target_user_id'   => $targetId > 0 ? $targetId : null,
                'target_name'      => $nickname,
                'source'           => $source,
                'cleared_count'    => (int) Arr::get($own, 'cleared'),
                'expired_count'    => (int) Arr::get($expiredByUser, "{$targetId}.expired"),
                'failed_count'     => (int) Arr::get($own, 'failed'),
            ]);
        }
    }

    /**
     * 這一輪涉及的同仁 id => 暱稱
     *
     * ⚠ 手動清除即使「0 則可清」也要留一列紀錄 —— **按了按鈕這件事本身**
     * 就是要被稽核的，不是只有清到東西才算。
     *
     * ⚠ 名字一次查齊（一趟查詢）再用 `firstWhere` 比對，不要在迴圈裡查 DB。
     *
     * @param array    $touchedIds 這一輪實際動到的 user_id
     * @param int|null $userId     手動時的指定對象
     * @return array<int, string>
     */
    private function targetNames(array $touchedIds, $userId)
    {
        $candidates = $this->candidates();

        if (filled($userId)) {
            $user = $candidates->firstWhere('id', (int) $userId);

            return filled($user) ? [(int) $userId => (string) $user->nickname] : [];
        }

        $names = [];

        foreach ($touchedIds as $id) {
            if ((int) $id < 1) {
                // user_id 是 null 的紀錄（帳號已刪除）—— 還是要有一列稽核
                $names[0] = trans('notification.msg.dm_wipe_removed_account');

                continue;
            }

            $user = $candidates->firstWhere('id', (int) $id);

            $names[(int) $id] = filled($user) ? (string) $user->nickname : "#{$id}";
        }

        return $names;
    }

    /**
     * 設定頁要的資料
     *
     * @return array
     */
    public function settings()
    {
        $windowStart = $this->windowStart();
        $counts = $this->messageRepository->countByUser($windowStart);

        $candidates = $this->candidates()
            ->map(function ($user) use ($counts) {
                $own = (array) Arr::get($counts, (int) $user->id, []);

                return [
                    'id'       => (int) $user->id,
                    'nickname' => (string) $user->nickname,
                    'pending'  => (int) Arr::get($own, 'pending'),
                    // ⚠ 這個數字一定要顯示：它是「後台永遠清不掉的那些」
                    'expired'  => (int) Arr::get($own, 'expired'),
                ];
            })
            ->values()
            ->all();

        return [
            'auto_enabled' => $this->appSettingService->getBool(AppSettingService::KEY_DM_WIPE_AUTO_ENABLED),
            'keep_hours'   => $this->keepHours(),
            'window_hours' => (int) config('constants.STAFF_DM_WIPE.DELETE_WINDOW_HOURS'),
            'min_hours'    => (int) config('constants.STAFF_DM_WIPE.MIN_KEEP_HOURS'),
            'max_hours'    => (int) config('constants.STAFF_DM_WIPE.MAX_KEEP_HOURS'),
            'candidates'   => $candidates,
            'logs'         => $this->recentLogs(),
        ];
    }

    /**
     * 最近的清除紀錄
     *
     * @return array
     */
    private function recentLogs()
    {
        return $this->logRepository->getRecent(self::RECENT_LOG_LIMIT)
            ->map(function ($log) {
                return [
                    'operator' => (string) $log->operator_name,
                    'target'   => (string) $log->target_name,
                    'source'   => (int) $log->source,
                    'cleared'  => (int) $log->cleared_count,
                    'expired'  => (int) $log->expired_count,
                    'failed'   => (int) $log->failed_count,
                    'at'       => (string) $log->created_at->format('Y-m-d H:i'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 更新設定
     *
     * @param array $params
     * @param int   $userId
     * @return void
     */
    public function updateSettings(array $params, $userId)
    {
        $this->appSettingService->putMany([
            // ⚠ 沒勾時前端送 null，不要送 false —— `filled(false)` 是 true
            AppSettingService::KEY_DM_WIPE_AUTO_ENABLED => filled(Arr::get($params, 'auto_enabled')) ? 1 : 0,
            AppSettingService::KEY_DM_WIPE_KEEP_HOURS   => (int) Arr::get($params, 'keep_hours'),
        ], $userId);
    }

    /**
     * 清除對象名單（同一個實例內只查一次）
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function candidates()
    {
        if (blank($this->candidateCache)) {
            $this->candidateCache = $this->userRepository->getDmWipeCandidates();
        }

        return $this->candidateCache;
    }

    /**
     * 刪除窗口的起點 —— 比這個時間更早送出的就永久刪不掉了
     *
     * @return Carbon
     */
    private function windowStart()
    {
        return now()->subHours((int) config('constants.STAFF_DM_WIPE.DELETE_WINDOW_HOURS'));
    }

    /**
     * 自動清除要保留最近幾小時
     *
     * @return int
     */
    private function keepHours()
    {
        return $this->appSettingService->getInt(
            AppSettingService::KEY_DM_WIPE_KEEP_HOURS,
            (int) config('constants.STAFF_DM_WIPE.DEFAULT_KEEP_HOURS')
        );
    }

    /**
     * 手動清除的結果文案
     *
     * ⚠ **過期的數量一定要講出來**。只回「已清除 N 則」會讓人以為乾淨了，
     * 而過期那些永久留在他手機裡 —— 他會因此不去做真正有效的事
     * （請同仁自己刪掉那段對話、或換帳號）。
     *
     * @param array  $counts
     * @param string $nickname
     * @return string
     */
    private function resultMessage(array $counts, $nickname)
    {
        $params = [
            'name'    => $nickname,
            'cleared' => (int) Arr::get($counts, 'cleared'),
            'expired' => (int) Arr::get($counts, 'expired'),
            'failed'  => (int) Arr::get($counts, 'failed'),
        ];

        if ((int) Arr::get($counts, 'failed') > 0) {
            return trans('notification.msg.dm_wipe_partial', $params);
        }

        if ((int) Arr::get($counts, 'expired') > 0) {
            return trans('notification.msg.dm_wipe_with_expired', $params);
        }

        return trans('notification.msg.dm_wipe_done', $params);
    }

    /**
     * @param string $message
     * @return array
     */
    private function failure($message)
    {
        return ['ok' => false, 'cleared' => 0, 'expired' => 0, 'failed' => 0, 'message' => $message];
    }
}

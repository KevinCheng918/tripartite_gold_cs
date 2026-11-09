<?php

namespace App\Services;

use App\Models\AutoReplyTicket;
use App\Repositories\AutoReplyTicketEscalationRepository;
use App\Repositories\AutoReplyTicketRepository;
use App\Repositories\UserRepository;
use App\Services\Notify\NoticeText;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 求助單逾時升級：催到第 N 次就打電話把人叫起來
 *
 * ```
 * 第 1～9 次   群組 tag（現行行為，一行不動）
 * 第 10 次     電話 #1 ＋ 私訊當班人
 *   +5 分       電話 #2（單還沒處理的話）
 *   +10 分      電話 #3
 *   +11 分      私訊勾選清單        ← 不等，排程下一輪就發
 *   之後        停止升級（群組提醒仍照常催到上限）
 * ```
 *
 * ⚠⚠ **「沒接」實作成「單還沒被處理」。** 系統分不出「沒接」與「接了但
 * 斷線」—— 那通電話接起來一定會斷（不串音訊），所以通話狀態不可靠。
 * 私訊同理（bot 讀不到已讀）。唯一可信的訊號是求助單自己的 status。
 *
 * ⚠ **刻意不掛在 `AutoReplySupportService::remindOne()`**：撥號重試是
 * 5 分鐘一次、而提醒是 10 分鐘一次，兩者對不上。獨立的每分鐘排程自己查，
 * 既有的提醒流程一行都不用動。
 *
 * 詳見 .claude/knowledge/features/ticket-overdue-escalation.md
 */
class TicketEscalationService
{
    private $ticketRepository;
    private $repository;
    private $userRepository;
    private $appSettingService;
    private $chatService;
    private $staffDm;
    private $supportGroup;
    private $callService;
    private $noticeText;

    public function __construct(
        AutoReplyTicketRepository $ticketRepository,
        AutoReplyTicketEscalationRepository $repository,
        UserRepository $userRepository,
        AppSettingService $appSettingService,
        TelegramChatService $chatService,
        StaffDmService $staffDm,
        SupportGroupService $supportGroup,
        TelegramCallService $callService,
        NoticeText $noticeText
    ) {
        $this->ticketRepository = $ticketRepository;
        $this->repository = $repository;
        $this->userRepository = $userRepository;
        $this->appSettingService = $appSettingService;
        // 當班是誰，跟求助單提醒用同一套判斷
        $this->chatService = $chatService;
        $this->staffDm = $staffDm;
        // 私訊送不出去時要補回群組，不要靜靜失敗
        $this->supportGroup = $supportGroup;
        $this->callService = $callService;
        $this->noticeText = $noticeText;
    }

    /**
     * 升級功能是否啟用
     *
     * ⚠ **預設關**：這功能會私訊人**而且會撥號**，而收件人清單一開始是空的。
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->appSettingService->getBool(AppSettingService::KEY_ESCALATION_ENABLED);
    }

    /**
     * 催到第幾次開始升級
     *
     * @return int
     */
    public function triggerAt()
    {
        return $this->appSettingService->getInt(
            AppSettingService::KEY_ESCALATION_TRIGGER_AT,
            (int) config('constants.TICKET_ESCALATION.DEFAULT.TRIGGER_AT')
        );
    }

    /**
     * 跑一輪
     *
     * @return int 做了幾個升級動作
     */
    public function run()
    {
        // 短路：設定層的判斷先於任何查詢（這支每分鐘跑）
        if (!$this->isEnabled()) {
            return 0;
        }

        $tickets = $this->ticketRepository->getEscalationCandidates($this->triggerAt());

        if ($tickets->isEmpty()) {
            return 0;
        }

        $history = $this->repository->getByTicketIds($tickets->pluck('id')->all());

        /*
         * ⚠ **當班人員與主管名單整輪只查一次**。這支每分鐘跑，同時有好幾張
         * 單在升級中是常態 —— 逐張去查就是那個張數的查詢，而一分鐘內
         * 名單不會變。比照 `CustomerFollowupService::askOnDuty()`。
         */
        $onDutyIds = $this->chatService->getOnDutyUserIds();

        /*
         * ⚠ **撥號與私訊要用兩份不同的名單**，不能共用一份：
         *
         * - 撥號要 `telegram_username`（`getMentionableByIds()` 有）
         * - 私訊要 `telegram_user_id` ＋ `telegram_dm_ready`
         *   （只有 `getForDmByIds()` 有，而且那支的註解寫明少了
         *   `telegram_dm_ready` 會讓所有人被判定成沒綁定）
         *
         * 兩支的 select 欄位不重疊，拿錯一份會靜默失敗。
         */
        $callTargets = collect($this->userRepository->getMentionableByIds($onDutyIds));
        $dmTargets = collect($this->userRepository->getForDmByIds($onDutyIds));
        $managers = collect($this->userRepository->getForDmByIds(
            $this->appSettingService->getIntList(AppSettingService::KEY_ESCALATION_MANAGER)
        ));

        $done = 0;

        foreach ($tickets as $ticket) {
            $records = collect(Arr::get($history, $ticket->id, []));

            if ($this->escalateOne($ticket, $records, $callTargets, $dmTargets, $managers)) {
                $done++;
            }
        }

        return $done;
    }

    /**
     * 決定這張單的下一步並執行
     *
     * @param AutoReplyTicket $ticket
     * @param Collection      $records     這張單既有的升級紀錄
     * @param Collection      $callTargets 當班、有 `telegram_username` 可撥的人
     * @param Collection      $dmTargets   當班、可私訊的人（欄位不同，見 `run()`）
     * @param Collection      $managers    勾選清單
     * @return bool 有做事才算 true
     */
    private function escalateOne(
        AutoReplyTicket $ticket,
        Collection $records,
        Collection $callTargets,
        Collection $dmTargets,
        Collection $managers
    ) {
        $stages = (array) config('constants.TICKET_ESCALATION.STAGE');
        $maxCalls = (int) config('constants.TICKET_ESCALATION.MAX_CALLS');

        $calls = $records->where('stage', (int) Arr::get($stages, 'CALL'));
        $callCount = $calls->count();

        // 一、還沒撥滿 → 撥（第一通順便私訊當班人）
        if ($callCount < $maxCalls) {
            if (!$this->isCallDue($calls)) {
                return false;
            }

            $this->call($ticket, $callTargets, $callCount + 1);

            // 第一通才私訊 —— 每通都私訊的話，手機響三次、訊息也三則
            if ($callCount === 0) {
                $this->dmOnDuty($ticket, $dmTargets);
            }

            return true;
        }

        // 二、撥滿了、而且還沒私訊過勾選清單 → 私訊（不等，需求方 2026-11-09）
        if ($records->where('stage', (int) Arr::get($stages, 'DM_MANAGER'))->isEmpty()) {
            $this->dmManagers($ticket, $managers, $callCount);

            return true;
        }

        // 三、都做完了就停。群組提醒仍會照常催到上限
        return false;
    }

    /**
     * 這一通該撥了嗎
     *
     * @param Collection $calls 既有的撥號紀錄
     * @return bool
     */
    private function isCallDue(Collection $calls)
    {
        if ($calls->isEmpty()) {
            return true;
        }

        $interval = $this->appSettingService->getInt(
            AppSettingService::KEY_ESCALATION_CALL_INTERVAL,
            (int) config('constants.TICKET_ESCALATION.DEFAULT.CALL_INTERVAL')
        );

        $last = $calls->max('created_at');

        return filled($last) && now()->greaterThanOrEqualTo($last->copy()->addMinutes($interval));
    }

    /**
     * 撥給當班的人
     *
     * ⚠ **撥給每一個當班的人**，不是只撥第一個 —— 那張單已經逾時一個多小時，
     * 這時候挑人沒有意義。
     *
     * @param AutoReplyTicket $ticket
     * @param Collection      $onDuty
     * @param int             $attempt
     * @return void
     */
    private function call(AutoReplyTicket $ticket, Collection $onDuty, $attempt)
    {
        $stages = (array) config('constants.TICKET_ESCALATION.STAGE');
        $results = (array) config('constants.TICKET_ESCALATION.RESULT');
        $seconds = $this->appSettingService->getInt(
            AppSettingService::KEY_ESCALATION_RING_SECONDS,
            (int) config('constants.TICKET_ESCALATION.DEFAULT.RING_SECONDS')
        );

        $rows = [];

        foreach ($onDuty as $user) {
            // 沒填 Telegram 帳號就撥不出去（回 null）—— 跟「tag 不到他」同一個根源
            $outcome = $this->callService->ring(
                $this->callService->targetFor($user->telegram_username),
                $seconds
            );

            $rows[] = $this->row($ticket, Arr::get($stages, 'CALL'), $attempt, $user, [
                'result' => $this->callResult($outcome, $results),
                'detail' => $this->callDetail($outcome),
            ]);
        }

        // 沒有人在班 —— 也要記一列，否則統計看起來像這次升級沒發生
        if (blank($rows)) {
            $rows[] = $this->row($ticket, Arr::get($stages, 'CALL'), $attempt, null, [
                'result' => (int) Arr::get($results, 'UNDELIVERABLE'),
                'detail' => 'NO_ON_DUTY',
            ]);
        }

        $this->repository->record($rows);

        Log::info('求助單逾時升級：已撥號', [
            'ticket_id' => $ticket->id,
            'attempt'   => $attempt,
            'targets'   => $onDuty->count(),
        ]);
    }

    /**
     * 撥號結果 → `result` 欄位
     *
     * @param array $outcome `TelegramCallService::ring()` 的回傳
     * @param array $results
     * @return int
     */
    private function callResult(array $outcome, array $results)
    {
        if (Arr::get($outcome, 'ok')) {
            return (int) Arr::get($results, 'OK');
        }

        /*
         * 不可重試的錯誤（隱私設定、被封鎖、找不到對象）算「被拒絕」；
         * 其餘（沒設定、process 掛掉）算「送不出去」。
         *
         * ⚠ 這個分類是給統計看的 —— 「被拒絕」要去調對方的隱私設定，
         * 「送不出去」是我們這邊的問題，兩種要分得出來。
         */
        return Arr::get($outcome, 'retryable')
            ? (int) Arr::get($results, 'UNDELIVERABLE')
            : (int) Arr::get($results, 'REJECTED');
    }

    /**
     * @param array $outcome
     * @return string|null
     */
    private function callDetail(array $outcome)
    {
        if (Arr::get($outcome, 'ok')) {
            // ⚠ 僅供記錄：接起來會立刻斷線，分不出「沒接」與「接了就斷」
            return Arr::get($outcome, 'answered') ? 'ANSWERED' : null;
        }

        return (string) Arr::get($outcome, 'error');
    }

    /**
     * 私訊當班人
     *
     * @param AutoReplyTicket $ticket
     * @param Collection      $onDuty
     * @return void
     */
    private function dmOnDuty(AutoReplyTicket $ticket, Collection $onDuty)
    {
        $this->dm(
            $ticket,
            $onDuty,
            (int) config('constants.TICKET_ESCALATION.STAGE.DM_ON_DUTY'),
            strtr((string) config('constants.TICKET_ESCALATION.DM_ON_DUTY'), $this->ticketReplace($ticket))
        );
    }

    /**
     * 私訊勾選清單
     *
     * @param AutoReplyTicket $ticket
     * @param Collection      $managers
     * @param int             $calls 撥了幾通
     * @return void
     */
    private function dmManagers(AutoReplyTicket $ticket, Collection $managers, $calls)
    {
        $replace = array_merge($this->ticketReplace($ticket), ['{calls}' => $calls]);

        $this->dm(
            $ticket,
            $managers,
            (int) config('constants.TICKET_ESCALATION.STAGE.DM_MANAGER'),
            strtr((string) config('constants.TICKET_ESCALATION.DM_MANAGER'), $replace)
        );
    }

    /**
     * 私訊一批人並記錄
     *
     * ⚠ **送不出去的人要補回支援群組**（比照班表通知）：沒私訊過 bot 的人
     * 收不到，靜靜失敗的話沒有人知道升級對他無效。
     *
     * @param AutoReplyTicket $ticket
     * @param Collection      $users
     * @param int             $stage
     * @param string          $text
     * @return void
     */
    private function dm(AutoReplyTicket $ticket, Collection $users, $stage, $text)
    {
        $results = (array) config('constants.TICKET_ESCALATION.RESULT');
        $rows = [];
        $failed = [];

        foreach ($users as $user) {
            /*
             * ⚠ **`send()` 回的是 bool，不要包 `filled()`** ——
             * `filled(false)` 在 Laravel 是 **true**，包了之後每一次失敗
             * 都會被當成成功，而且完全不報錯。這個專案的
             * `NotificationSettingService` 註解警告過同一件事。
             *
             * 先問 `canDm()` 是為了把「沒綁定」跟「送出去但失敗」分開記 ——
             * 前者要請他去私訊機器人，後者是我們這邊的問題。
             */
            $ready = $this->staffDm->canDm($user);
            $ok = $ready && $this->staffDm->send($user, $text);

            if (!$ok) {
                $failed[] = (string) $user->nickname;
            }

            $rows[] = $this->row($ticket, $stage, 1, $user, [
                'result' => $ok
                    ? (int) Arr::get($results, 'OK')
                    : (int) Arr::get($results, 'UNDELIVERABLE'),
                'detail' => $ok ? null : ($ready ? 'SEND_FAILED' : 'DM_NOT_READY'),
            ]);
        }

        if (blank($rows)) {
            $rows[] = $this->row($ticket, $stage, 1, null, [
                'result' => (int) Arr::get($results, 'UNDELIVERABLE'),
                'detail' => 'NO_RECIPIENT',
            ]);
        }

        $this->repository->record($rows);

        if (filled($failed)) {
            $this->noticeUndeliverable($failed);
        }

        Log::info('求助單逾時升級：已私訊', [
            'ticket_id' => $ticket->id,
            'stage'     => $stage,
            'sent'      => count($rows) - count($failed),
            'failed'    => $failed,
        ]);
    }

    /**
     * 有人收不到私訊 → 補回支援群組
     *
     * @param array<string> $names
     * @return void
     */
    private function noticeUndeliverable(array $names)
    {
        if (!$this->supportGroup->isConfigured()) {
            return;
        }

        $this->supportGroup->send(
            strtr((string) config('constants.TICKET_ESCALATION.UNDELIVERABLE_NOTICE'), [
                '{names}' => implode('、', $names),
            ]),
            null,
            null,
            AutoReplySupportService::NOTICE_TYPE
        );
    }

    /**
     * 文案的共用替換
     *
     * @param AutoReplyTicket $ticket
     * @return array<string, string>
     */
    private function ticketReplace(AutoReplyTicket $ticket)
    {
        $escalation = (array) config('constants.TICKET_ESCALATION');

        return [
            '{group}'    => filled($ticket->group) ? (string) $ticket->group->title : '-',
            '{question}' => $this->noticeText->shorten(
                (string) $ticket->question,
                (int) Arr::get($escalation, 'QUESTION_CHARS')
            ),
            '{seq}'    => (int) $ticket->remind_count,
            '{waited}' => $this->noticeText->waited($ticket->created_at, $escalation),
        ];
    }

    /**
     * 組一列紀錄
     *
     * @param AutoReplyTicket                 $ticket
     * @param int                             $stage
     * @param int                             $attempt
     * @param \App\Models\User|object|null    $user
     * @param array                           $extra result / detail
     * @return array
     */
    private function row(AutoReplyTicket $ticket, $stage, $attempt, $user, array $extra)
    {
        return array_merge([
            'auto_reply_ticket_id' => $ticket->id,
            'stage'                => (int) $stage,
            'seq'                  => (int) $ticket->remind_count,
            'attempt'              => (int) $attempt,
            'user_id'              => filled($user) ? (int) $user->id : null,
            'user_name'            => filled($user) ? (string) $user->nickname : null,
        ], $extra);
    }
}

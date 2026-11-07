<?php

namespace App\Services;

use App\Models\AutoReplyResume;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Repositories\AutoReplyResumeRepository;
use App\Repositories\TelegramRepository;
use App\Services\Notify\NoticeText;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 自動回覆關掉之後自己勾回來
 *
 * 客服取消勾選自動回覆改人工，處理完忘記勾回來 —— 那個群組從此一直是人工。
 * 這支負責：關的時候記一筆、排程時把靜置夠久的勾回來、通知內部支援群組。
 *
 * ⚠ **判斷依據是「關閉事件」而不是 `auto_reply = 0`**。欄位分不出「剛被關掉」
 * 與「從來沒開過」，而後者是刻意一直人工的群組 —— 誤開會把自動回覆的訊息
 * 發給客人，而且收不回來。所以整支服務只認這張表裡的紀錄。
 *
 * 詳見 .claude/knowledge/features/auto-reply-resume.md
 */
class AutoReplyResumeService
{
    private $repository;
    private $telegramRepository;
    private $appSettingService;
    private $supportGroup;
    private $noticeText;

    public function __construct(
        AutoReplyResumeRepository $repository,
        TelegramRepository $telegramRepository,
        AppSettingService $appSettingService,
        SupportGroupService $supportGroup,
        NoticeText $noticeText
    ) {
        $this->repository = $repository;
        $this->telegramRepository = $telegramRepository;
        $this->appSettingService = $appSettingService;
        $this->supportGroup = $supportGroup;
        $this->noticeText = $noticeText;
    }

    /**
     * 通知類型（`constants.SUPPORT_TOPIC.TYPES` 的 key）
     *
     * @var string
     */
    const NOTICE_TYPE = 'auto_reply_resume';

    /**
     * 自動恢復功能是否啟用
     *
     * @return bool
     */
    public function isEnabled()
    {
        return $this->appSettingService->getBool(AppSettingService::KEY_AUTO_REPLY_RESUME_ENABLED);
    }

    /**
     * modal 的預填分鐘數
     *
     * ⚠ 這**不是**生效的靜置時間 —— 真正生效的是每一筆紀錄上的
     * `quiet_minutes`（關閉的人當場填的）。這個值只是省去每次打字。
     *
     * @return int
     */
    public function defaultMinutes()
    {
        $default = (int) config('constants.AUTO_REPLY_RESUME.DEFAULT_MINUTES');
        $minutes = $this->appSettingService->getInt(
            AppSettingService::KEY_AUTO_REPLY_RESUME_DEFAULT_MINUTES,
            $default
        );

        return $this->clampMinutes($minutes, $default);
    }

    // ---------------------------------------------------------------
    //  一、關閉 / 開啟時記帳（由 AutoReplyService::toggle 呼叫）
    // ---------------------------------------------------------------

    /**
     * 記一筆關閉
     *
     * ⚠ **功能關著時什麼都不記**（回 null）。記了也不會有人去恢復，而且更糟：
     * 功能關著期間累積的紀錄會在某天被打開的那一分鐘**同時全部恢復** ——
     * 那正是需求方否決掉的「一次全部開回來」。要知道上線前有哪些關著的，
     * 用 `auto-reply:resume-audit`（它看 `auto_reply_at`，不看這張表）。
     *
     * ⚠ **永久關閉也要記**（`mode` = PERMANENT、`quiet_minutes` = null）：
     * 之後要回答「這個群組是什麼時候、誰決定不自動回覆的」只能靠這張表。
     *
     * ⚠ **同一個群組原本掛著的待恢復要先作廢**：連關兩次（改了主意、
     * 從暫時改成永久）會留下兩筆 PENDING，排程就會用舊那筆的分鐘數恢復。
     *
     * ⚠ **分鐘數沒帶時退回預設值而不是報錯**：modal 一定會帶，所以沒帶代表
     * 不是從 modal 來的。那時擋下來只會讓開關停在半套狀態（DB 已關、
     * 畫面卻說失敗），退回預設反而兩邊一致。範圍不合法仍由 Request 擋。
     *
     * ⚠ 呼叫端負責包 transaction（它同時在改 `telegram_group.auto_reply`）。
     *
     * @param TelegramGroup $group
     * @param User|null     $operator     操作者（帳號被刪時名稱靠快照留著）
     * @param int           $mode         見 constants.AUTO_REPLY_RESUME.MODE
     * @param int|null      $quietMinutes 暫時關閉的靜置分鐘數，沒帶就用預設
     * @return AutoReplyResume|null 功能關著時為 null
     */
    public function recordOff(TelegramGroup $group, $operator, $mode, $quietMinutes)
    {
        // 短路：設定層的判斷先於任何查詢
        if (!$this->isEnabled()) {
            return null;
        }

        $modes = (array) config('constants.AUTO_REPLY_RESUME.MODE');
        $statuses = (array) config('constants.AUTO_REPLY_RESUME.STATUS');
        $isTemporary = (int) $mode === (int) Arr::get($modes, 'TEMPORARY');
        $userId = filled($operator) ? (int) $operator->id : null;
        $minutes = filled($quietMinutes) ? (int) $quietMinutes : $this->defaultMinutes();

        $this->voidPending($group->id);

        $resume = $this->repository->create([
            'telegram_group_id' => $group->id,
            'user_id'           => $userId,
            'operator_name'     => filled($operator) ? (string) $operator->nickname : null,
            'off_at'            => now(),
            'quiet_minutes'     => $isTemporary ? $minutes : null,
            'mode'              => $isTemporary
                ? (int) Arr::get($modes, 'TEMPORARY')
                : (int) Arr::get($modes, 'PERMANENT'),
            'status'            => $isTemporary
                ? (int) Arr::get($statuses, 'PENDING')
                : (int) Arr::get($statuses, 'PERMANENT'),
        ]);

        /*
         * 永久關閉要留 Log：它會讓一個群組從此不自動回覆，而目前沒有為它
         * 獨立的權限 keyword（沿用 telegram_chat.reply）—— 事後要追是誰決定的
         * 只剩這條線索與那一列紀錄。
         */
        if (!$isTemporary) {
            Log::info('自動回覆被設為永久關閉', [
                'group_id' => $group->id,
                'user_id'  => $userId,
            ]);
        }

        return $resume;
    }

    /**
     * 客服自己把開關勾回來了 —— 把待恢復結案
     *
     * 不結案的話排程仍撈得到那筆，而 `getDueForResume()` 的
     * `auto_reply = 0` 條件雖然擋得住誤動作，聊天視窗卻會一直顯示一個
     * 不會到來的倒數。
     *
     * ⚠ 呼叫端負責包 transaction。
     *
     * @param int $groupId
     * @return void
     */
    public function closePendingByManual($groupId)
    {
        /*
         * ⚠ **這一支不看功能開關**，跟 `recordOff()` 不同。
         *
         * 功能是可以被中途關掉的，而那時既有的 PENDING 紀錄還留著 ——
         * 短路掉的話，客服自己勾回來之後那筆仍是 PENDING，之後功能再被打開
         * 就會對一個已經開著的對話「恢復」並發一則莫名其妙的通知。
         */
        $this->markPending($groupId, (int) config('constants.AUTO_REPLY_RESUME.STATUS.MANUAL'));
    }

    // ---------------------------------------------------------------
    //  二、排程恢復
    // ---------------------------------------------------------------

    /**
     * 跑一輪：把靜置夠久的對話勾回自動回覆，並通知內部支援群組
     *
     * @return int 恢復了幾個對話
     */
    public function resumeDue()
    {
        // 短路：設定層的判斷先於任何查詢（這支每分鐘跑）
        if (!$this->isEnabled()) {
            return 0;
        }

        /*
         * 封存群組的待恢復先作廢 —— 跟恢復無關，但同一輪處理掉才不用另掛
         * 一支排程。作廢不通知：恢復一個封存的對話沒有意義，為它發一則
         * 只是雜訊。
         */
        $this->voidArchived();

        /*
         * ⚠ **沒有 Claude 憑證時什麼都不做，但也不作廢那些紀錄**：
         * 開起來只會讓客人一直收到「稍等」（`AutoReplyService::toggle()`
         * 本來就擋這件事）。憑證設好之後這些紀錄還要繼續生效，
         * 所以是「等」而不是「丟掉」。
         */
        if (!$this->appSettingService->has(AppSettingService::KEY_CLAUDE_TOKEN)) {
            return 0;
        }

        $due = $this->repository->getDueForResume();

        if ($due->isEmpty()) {
            return 0;
        }

        $resumed = $this->flipOn($due);

        if (blank($resumed)) {
            return 0;
        }

        $this->notifyResumed($resumed);

        return count($resumed);
    }

    /**
     * 把這幾筆對應的群組勾回自動回覆
     *
     * ⚠ 整批在同一個 transaction 裡：`telegram_group` 與 `auto_reply_resume`
     * 是兩張表，開關開了卻沒結案的話下一輪會再開一次、再通知一次。
     *
     * @param Collection<AutoReplyResume> $due
     * @return array<int, AutoReplyResume> 真正恢復成功的那幾筆
     */
    private function flipOn(Collection $due)
    {
        $statuses = (array) config('constants.AUTO_REPLY_RESUME.STATUS');
        $resumed = [];
        $brokenIds = [];

        foreach ($due as $resume) {
            /*
             * 關聯撈不到群組 —— 理論上不會發生（FK 是 cascadeOnDelete），
             * 但真的發生時這筆會每分鐘被撈出來重試一次。作廢而不是標成
             * 「已恢復」：什麼都沒恢復，記成恢復了會讓紀錄說謊。
             */
            if (blank($resume->group)) {
                $brokenIds[] = $resume->id;
                continue;
            }

            $resumed[] = $resume;
        }

        DB::transaction(function () use ($resumed, $brokenIds, $statuses) {
            // 整批一句 whereIn —— 逐筆 setAutoReply() 是 2N 次查詢（update + refresh）
            $this->telegramRepository->setAutoReplyBulk(
                collect($resumed)->pluck('telegram_group_id')->all(),
                true
            );

            $this->repository->markStatus(
                collect($resumed)->pluck('id')->all(),
                (int) Arr::get($statuses, 'RESUMED')
            );
            $this->repository->markStatus($brokenIds, (int) Arr::get($statuses, 'VOIDED'));
        });

        foreach ($resumed as $resume) {
            Log::info('自動回覆已自動恢復', [
                'group_id'      => $resume->telegram_group_id,
                'resume_id'     => $resume->id,
                'off_at'        => (string) $resume->off_at,
                'quiet_minutes' => $resume->quiet_minutes,
                'off_by'        => $resume->user_id,
            ]);
        }

        return $resumed;
    }

    /**
     * 群組已封存卻還掛著待恢復 → 作廢
     *
     * @return void
     */
    private function voidArchived()
    {
        $ids = $this->repository->getPendingForArchivedGroups()->pluck('id')->all();

        if (blank($ids)) {
            return;
        }

        $this->repository->markStatus($ids, (int) config('constants.AUTO_REPLY_RESUME.STATUS.VOIDED'));
    }

    // ---------------------------------------------------------------
    //  三、通知
    // ---------------------------------------------------------------

    /**
     * 通知內部支援群組
     *
     * ⚠ **整輪併成一則**：這支每分鐘跑，同時到期五個就是五則連發。
     *
     * @param array<int, AutoReplyResume> $resumed
     * @return void
     */
    private function notifyResumed(array $resumed)
    {
        // 短路：群組沒設定就不必組字串
        if (!$this->supportGroup->isConfigured()) {
            return;
        }

        $this->supportGroup->send($this->buildNoticeText($resumed), null, null, self::NOTICE_TYPE);
    }

    /**
     * 組整則通知
     *
     * @param array<int, AutoReplyResume> $resumed
     * @return string
     */
    private function buildNoticeText(array $resumed)
    {
        $notice = (array) config('constants.AUTO_REPLY_RESUME.NOTICE');
        $text = (string) Arr::get($notice, 'HEADER');
        $max = (int) Arr::get($notice, 'MAX_LINES');
        $shown = 0;

        foreach ($resumed as $resume) {
            if ($shown >= $max) {
                break;
            }

            $text .= strtr((string) Arr::get($notice, 'LINE'), [
                '{group}'    => $this->noticeText->shorten(
                    filled($resume->group) ? (string) $resume->group->title : '-',
                    (int) Arr::get($notice, 'GROUP_CHARS')
                ),
                '{operator}' => $this->buildMention($resume, $notice),
                '{waited}'   => $this->noticeText->waited($resume->off_at, $notice),
                '{minutes}'  => (int) $resume->quiet_minutes,
            ]);
            $shown++;
        }

        if (count($resumed) > $shown) {
            $text .= strtr((string) Arr::get($notice, 'MORE_LINE'), [
                '{count}' => count($resumed) - $shown,
            ]);
        }

        return $text . (string) Arr::get($notice, 'FOOTER');
    }

    /**
     * 逐列 tag 各自的關閉者
     *
     * 需求方 2026-11-07 要 tag 本人。⚠ **逐列 tag 而不是全部堆在開頭**：
     * 一輪可能恢復好幾個群組、而且是不同人關的，堆在開頭就看不出哪個是誰的。
     *
     * ⚠ tag 不到人時退回顯示名字，**不是整則不發**：`telegram_username`
     * 是人工填的欄位，沒填就 tag 不到，但恢復這件事已經發生了 ——
     * 沉默只會讓人以為開關自己壞掉。比照
     * `TicketHandoverService::buildMentions()`。
     *
     * @param AutoReplyResume $resume
     * @param array           $notice
     * @return string
     */
    private function buildMention(AutoReplyResume $resume, array $notice)
    {
        $username = filled($resume->user) ? $resume->user->telegram_username : null;

        if (filled($username)) {
            return '@' . ltrim(trim($username), '@');
        }

        // 帳號還在就用現在的暱稱，已刪除就用當初存的快照
        $name = filled($resume->user) ? (string) $resume->user->nickname : (string) $resume->operator_name;

        return strtr((string) Arr::get($notice, 'NO_MENTION'), [
            '{name}' => filled($name) ? $name : '不明人員',
        ]);
    }

    // ---------------------------------------------------------------
    //  四、聊天視窗要顯示的倒數
    // ---------------------------------------------------------------

    /**
     * 這些群組各自的預計恢復時間
     *
     * 不顯示的話，客服會遇到「我明明關了它怎麼自己開了」。
     *
     * @param array $groupIds
     * @return array<int, string> key = telegram_group_id, value = ISO 8601 時間
     */
    public function resumeAtByGroupIds(array $groupIds)
    {
        // 短路：功能關著就沒有倒數可言，連查都不用查
        if (!$this->isEnabled() || blank($groupIds)) {
            return [];
        }

        $map = [];

        foreach ($this->repository->getPendingByGroupIds($groupIds) as $groupId => $resume) {
            $resumeAt = $resume->resumeAt();

            if (filled($resumeAt)) {
                $map[(int) $groupId] = $resumeAt->toIso8601String();
            }
        }

        return $map;
    }

    // ---------------------------------------------------------------
    //  五、上線盤點（一次性）
    // ---------------------------------------------------------------

    /**
     * 盤點「現在關著、但曾經真的自動回覆過」的群組
     *
     * 「記事件不記狀態」的代價是上線前就已經忘記勾回來的群組沒有紀錄。
     * 這支把可疑的列出來給人看，**一行都不寫入** —— 會把 `auto_reply`
     * 由 0 改成 1 的地方只有排程，而排程只看待恢復紀錄。
     *
     * @return Collection
     */
    public function auditCandidates()
    {
        return $this->telegramRepository->getAutoReplyAuditCandidates();
    }

    // ---------------------------------------------------------------
    //  內部
    // ---------------------------------------------------------------

    /**
     * 作廢某個群組目前掛著的待恢復
     *
     * @param int $groupId
     * @return void
     */
    private function voidPending($groupId)
    {
        $this->markPending($groupId, (int) config('constants.AUTO_REPLY_RESUME.STATUS.VOIDED'));
    }

    /**
     * 把某個群組目前掛著的待恢復改成某個狀態
     *
     * @param int $groupId
     * @param int $status
     * @return void
     */
    private function markPending($groupId, $status)
    {
        $pending = $this->repository->findPendingByGroup($groupId);

        if (blank($pending)) {
            return;
        }

        $this->repository->update($pending, [
            'status'     => $status,
            'resumed_at' => now(),
        ]);
    }

    /**
     * 把分鐘數夾回允許範圍
     *
     * 設定值是人填的，超出範圍就退回預設 —— 填 1 分鐘會讓 AI 在人工處理的
     * 中途插話，填一週等於這個功能對那一筆失效。
     *
     * @param int $minutes
     * @param int $default
     * @return int
     */
    private function clampMinutes($minutes, $default)
    {
        $min = (int) config('constants.AUTO_REPLY_RESUME.MIN_MINUTES');
        $max = (int) config('constants.AUTO_REPLY_RESUME.MAX_MINUTES');

        if ($minutes < $min || $minutes > $max) {
            return $default;
        }

        return $minutes;
    }
}

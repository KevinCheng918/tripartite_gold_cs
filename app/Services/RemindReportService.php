<?php

namespace App\Services;

use App\Repositories\AutoReplyTicketRemindRepository;
use App\Repositories\UserRepository;
use App\Services\Notify\EncouragementWriter;
use App\Services\Notify\NoticeText;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * 每日超時提醒統計
 *
 * 前一天的超時提醒，兩種收件人、內容不一樣（需求方 2026-10-06）：
 *
 * | 收件人 | 內容 |
 * |---|---|
 * | 設定頁勾選的人（主管以上，可多位） | **全部人的**：按單 ＋ 按人兩段 |
 * | **所有在職同仁**（扣掉上面那批） | 自己那份：被催到就是統計，沒被催到就是一句肯定 |
 *
 * ⚠ 按單與按人**不能互相換算**：當班人員會隨時段換人，同一張單催五次可能
 * tag 到三組不同的人，所以每次 tag 到誰在 `auto_reply_ticket_remind` 逐筆落地。
 *
 * ⚠ **沒事的日子兩種都要發**（需求方 2026-10-06 調整）。安靜不動時分不出
 * 「昨天沒事」還是「排程壞了」。沒事那天的那句肯定**由模型當天生成、不用公版**
 * —— 公版寫久了大家會自動略過，那就失去意義了。
 * 模型不可用時才退公版：沒有鼓勵的話，比整則不發好得多。
 *
 * ⚠ **三種期間共用這一支**（2026-10-10 加了週報與月報）：
 *
 * | type | 排程 | 統計 | 個人版 |
 * |---|---|---|---|
 * | `daily` | 每天 08:30 | 前一天 | ✅ 逐人發 |
 * | `weekly` | 每週一 08:30 | 上週一～上週日 | ❌ |
 * | `monthly` | 每月 1 號 08:30 | 上個月 | ❌ |
 *
 * ⚠ **週報與月報只發完整版**（需求方 2026-10-10）。日報每天已經逐人告訴
 * 他自己的狀況了，週月報再逐人發一次是同一件事講兩遍；而且週一那天同仁
 * 本來就會收到打卡週報的個人版，再多一則只是噪音。
 *
 * ⚠ **收件人三種共用同一份**（`KEY_REMIND_REPORT_MANAGER`，需求方指定）：
 * 同一件事的不同時間尺度，關心的是同一批人，分兩份只是多一個會忘記同步的地方。
 *
 * ⚠ **三種都在 08:30，跟日報同一分鐘**（需求方 2026-10-10 要求時間一致，
 * 原本週月報排在 11:30）。所以週一會同時送出日報與週報；1 號剛好是週一時
 * 還會再多一則月報。三則內容不同（昨天／上週／上個月），不是重複發送。
 *
 * 打卡報表維持在 11:00（需求方指定不動），跟這一組分開。
 */
class RemindReportService
{
    /** @var string 日報：前一天 */
    const TYPE_DAILY = 'daily';

    /** @var string 週報：上週一～上週日 */
    const TYPE_WEEKLY = 'weekly';

    /** @var string 月報：上個月 */
    const TYPE_MONTHLY = 'monthly';

    private $remindRepository;
    private $userRepository;
    private $staffDm;
    private $appSettingService;
    private $noticeText;
    private $encouragement;

    public function __construct(
        AutoReplyTicketRemindRepository $remindRepository,
        UserRepository $userRepository,
        StaffDmService $staffDm,
        AppSettingService $appSettingService,
        NoticeText $noticeText,
        EncouragementWriter $encouragement
    ) {
        $this->remindRepository = $remindRepository;
        $this->userRepository = $userRepository;
        $this->staffDm = $staffDm;
        $this->appSettingService = $appSettingService;
        // 日期與截短的排版三支共用，不各寫一份
        $this->noticeText = $noticeText;
        // 沒事的日子那句肯定由模型寫，失敗時退公版
        $this->encouragement = $encouragement;
    }

    /**
     * 跑一輪：完整版給勾選的人；日報另外逐人發個人版
     *
     * ⚠ 簽章在 2026-10-10 變過（原本是 `run($date, $dryRun)`）。
     * 呼叫端有 `RemindReportCommand` 與 `NotificationSettingService::testReport()`，
     * 漏改的話是執行期錯誤、不是編譯期。
     *
     * @param string      $type   self::TYPE_*
     * @param string|null $endsOn 以哪一天往回推（預設今天）。補發用
     * @param bool        $dryRun 只組內容不發送
     * @return array 結果，給 Command 顯示
     */
    public function run($type = self::TYPE_DAILY, $endsOn = null, $dryRun = false)
    {
        $range = $this->resolveRange($type, filled($endsOn) ? $endsOn : now()->toDateString());

        $fullText = $this->buildText($type, $range);
        $full = $this->sendFull($fullText, $dryRun);

        /*
         * ⚠ **只有日報發個人版**（需求方 2026-10-10）。
         * 週月報逐人再發一次是同一件事講兩遍，而且週一那天同仁本來就會
         * 收到打卡週報的個人版。
         */
        $personal = $type === self::TYPE_DAILY
            ? $this->sendPersonal(Arr::get($range, 'start'), $dryRun)
            : ['sent' => 0, 'sample' => null, 'failed' => []];

        return [
            'type'          => $type,
            'range'         => $range,
            'text'          => $fullText,
            'sent'          => Arr::get($full, 'sent', 0),
            'reason'        => Arr::get($full, 'reason'),
            'names'         => Arr::get($full, 'names', []),
            'personal_sent' => Arr::get($personal, 'sent', 0),
            'personal_text' => Arr::get($personal, 'sample'),
            'failed'        => array_merge(
                Arr::get($full, 'failed', []),
                Arr::get($personal, 'failed', [])
            ),
        ];
    }

    /**
     * 算出要統計哪一段
     *
     * ⚠ 都是「上一期」：日報發的是昨天、週一發的是上週、1 號發的是上個月。
     * 當期還沒過完，統計沒有意義。
     *
     * ⚠ 週的起點用 `startOfWeek()`（Carbon 預設週一），**不要用 `-7 days`**
     * —— 補發時（指定別的日期）那樣算出來的不會對齊週一。
     *
     * @param string $type
     * @param string $endsOn Y-m-d
     * @return array start / end（都是 Y-m-d）
     */
    private function resolveRange($type, $endsOn)
    {
        $base = Carbon::parse($endsOn);

        if ($type === self::TYPE_MONTHLY) {
            $start = $base->copy()->subMonthNoOverflow()->startOfMonth();

            return ['start' => $start->toDateString(), 'end' => $start->copy()->endOfMonth()->toDateString()];
        }

        if ($type === self::TYPE_WEEKLY) {
            $start = $base->copy()->startOfWeek()->subWeek();

            return ['start' => $start->toDateString(), 'end' => $start->copy()->addDays(6)->toDateString()];
        }

        // 日報：前一天一整天
        $day = $base->copy()->subDay()->toDateString();

        return ['start' => $day, 'end' => $day];
    }

    /**
     * 這一期的標題
     *
     * @param string $type
     * @return string
     */
    private function title($type)
    {
        $map = [
            self::TYPE_WEEKLY  => 'TITLE_WEEKLY',
            self::TYPE_MONTHLY => 'TITLE_MONTHLY',
        ];

        return (string) config(
            'constants.AUTO_REPLY.REMIND.REPORT.' . Arr::get($map, $type, 'TITLE_DAILY')
        );
    }

    /**
     * 文案裡「這一期」的說法（昨天／上週／上個月）
     *
     * @param string $type
     * @return string
     */
    private function periodWord($type)
    {
        $map = [
            self::TYPE_WEEKLY  => 'PERIOD_WEEKLY',
            self::TYPE_MONTHLY => 'PERIOD_MONTHLY',
        ];

        return (string) config(
            'constants.AUTO_REPLY.REMIND.REPORT.' . Arr::get($map, $type, 'PERIOD_DAILY')
        );
    }

    /**
     * 標題底下那一行期間
     *
     * ⚠ 日報沿用 `NoticeText::date()` 的「10/9（週五）」—— 那是既有的樣子，
     * 換成「2026-10-09 ～ 2026-10-09」只會變難讀。
     *
     * @param array  $report
     * @param string $type
     * @param array  $range
     * @return string
     */
    private function rangeText(array $report, $type, array $range)
    {
        if ($type === self::TYPE_DAILY) {
            return $this->noticeText->date(Arr::get($range, 'start'));
        }

        return strtr((string) Arr::get($report, 'RANGE'), [
            '{start}' => (string) Arr::get($range, 'start'),
            '{end}'   => (string) Arr::get($range, 'end'),
        ]);
    }

    /**
     * 測試發送：真的把昨天的完整統計私訊給勾選的收件人
     *
     * ⚠ 刻意**不是** dry-run —— 測試按鈕要回答的是「訊息到得了他手機嗎」，
     * 只組字串不發送的話，沒綁定、被封鎖這些真正會出事的狀況全都測不到。
     * 比照 `ShiftNoticeService::test()`。
     *
     * ⚠ **只發完整版**，不發個人版 —— 測試不該去打擾昨天被提醒到的同仁。
     *
     * @return array sent / reason / names / failed
     */
    public function test($type = self::TYPE_DAILY)
    {
        $range = $this->resolveRange($type, now()->toDateString());
        $text = (string) config('constants.AUTO_REPLY.REMIND.REPORT.TEST_PREFIX')
            . $this->buildText($type, $range);

        $result = $this->sendFull($text, false);

        return [
            'sent'   => Arr::get($result, 'sent', 0),
            'reason' => Arr::get($result, 'reason'),
            'names'  => Arr::get($result, 'names', []),
            'failed' => Arr::get($result, 'failed', []),
        ];
    }

    /**
     * 完整版 —— 發給設定頁勾選的人
     *
     * @param string $text
     * @param bool   $dryRun
     * @return array sent / reason / failed / names
     */
    private function sendFull($text, $dryRun)
    {
        $userIds = $this->appSettingService->getIntList(AppSettingService::KEY_REMIND_REPORT_MANAGER);

        if (blank($userIds)) {
            return ['sent' => 0, 'reason' => StaffDmService::SKIP_NO_RECIPIENT, 'failed' => [], 'names' => []];
        }

        /*
         * 空跑只回「幾個人會收到」，不判斷綁定狀態 —— `--dry-run` 要看的是
         * 內容與排版，誰收得到是另一件事。
         */
        if ($dryRun) {
            return ['sent' => count($userIds), 'reason' => null, 'failed' => [], 'names' => []];
        }

        return $this->staffDm->sendToUserIds($userIds, $text);
    }

    /**
     * 個人版 —— 發給每個昨天被提醒到的人
     *
     * ⚠ **沒被提醒到的人不發**：一則「您昨天沒事」只是噪音，
     * 而且會讓真的有事的那天被當成例行訊息忽略。
     *
     * ⚠ **一個人失敗不能讓整批停掉**：逐人送、逐人收結果，
     * 失敗的收進 `failed` 讓呼叫端回報。
     *
     * @param string $date
     * @param bool   $dryRun
     * @return array sent / sample / failed
     */
    private function sendPersonal($date, $dryRun)
    {
        // 個人版只有日報會走到，所以起訖是同一天
        $byUser = $this->groupByUser($this->remindRepository->getUserTicketsForRange($date, $date));

        /*
         * ⚠ **發給所有在職同仁，不只昨天被提醒到的人**（需求方 2026-10-06）。
         *
         * 沒被提醒到的人收到的是一句肯定，不是空白統計 —— 所以收件人範圍是
         * 「同仁」而不是「昨天出事的人」。
         *
         * ⚠ **主管以上（含主管）不收**（需求方 2026-10-11）：完整版的「依人員」
         * 早就濾掉他們（`withoutManagers()`），個人版照發的話等於統計說
         * 「不列主管」、私訊卻還在逐一通知主管，兩邊對不起來。
         * `getDmCandidates(true)` 連 Leader / Boss 一起排除（原本只排除管理者）。
         */

        /*
         * ⚠ **勾了完整統計的人不再收個人版**（需求方 2026-10-08）。
         *
         * 完整版已經包含「依人員」那一段，他在裡面看得到自己 ——
         * 再發一則個人版只是同一件事講兩遍，而且兩則同時間到，看起來像重複發送。
         */
        $fullRecipients = $this->appSettingService->getIntList(AppSettingService::KEY_REMIND_REPORT_MANAGER);

        $sent = 0;
        $failed = [];
        $sample = null;

        foreach ($this->userRepository->getDmCandidates(true) as $user) {
            $userId = (int) $user->id;

            if (in_array($userId, $fullRecipients, true)) {
                continue;
            }

            $rows = (array) Arr::get($byUser, $userId, []);

            /*
             * 有被提醒到 → 發統計；沒有 → 發一句肯定。
             *
             * ⚠ 肯定那句是逐人叫模型生成的（每次幾秒），所以**空跑時不要生**：
             * `--dry-run` 只是要看排版，不該為了預覽燒掉額度也不該跑好幾分鐘。
             */
            $text = filled($rows)
                ? $this->buildPersonalText($date, (string) $user->nickname, $rows)
                : $this->buildClearText($date, (string) $user->nickname, $dryRun);

            $sample = filled($sample) ? $sample : $text;

            if ($dryRun) {
                $sent++;

                continue;
            }

            if ($this->staffDm->send($user, $text)) {
                $sent++;

                continue;
            }

            $failed[] = (string) $user->nickname;
        }

        return ['sent' => $sent, 'sample' => $sample, 'failed' => $failed];
    }

    /**
     * 組「昨天沒事」那份
     *
     * @param string $date
     * @param string $name
     * @param bool   $dryRun 空跑時不叫模型，直接用公版
     * @return string
     */
    private function buildClearText($date, $name, $dryRun)
    {
        $report = (array) config('constants.AUTO_REPLY.REMIND.REPORT');
        $praise = $dryRun
            ? (string) Arr::get($report, 'ENCOURAGE.PERSON_FALLBACK')
            : $this->encouragement->forPerson($name);

        return strtr((string) Arr::get($report, 'PERSONAL_CLEAR'), [
            '{name}'   => $name,
            '{date}'   => $this->noticeText->date($date),
            '{praise}' => $praise,
        ]);
    }

    /**
     * 把逐筆的（人、單）彙總成「user_id => 那個人的那幾筆」
     *
     * @param iterable $rows
     * @return array<int, array>
     */
    private function groupByUser($rows)
    {
        $byUser = [];

        foreach ($rows as $row) {
            $byUser[(int) $row->user_id][] = $row;
        }

        return $byUser;
    }

    /**
     * 組個人版
     *
     * @param string $date
     * @param string $name
     * @param array  $rows 這個人的那幾筆
     * @return string
     */
    private function buildPersonalText($date, $name, array $rows)
    {
        $report = (array) config('constants.AUTO_REPLY.REMIND.REPORT');
        $statusLabels = (array) Arr::get($report, 'STATUS_LABEL');
        $statusIcons = (array) Arr::get($report, 'STATUS_ICON');

        $text = strtr((string) Arr::get($report, 'PERSONAL_HEADER'), ['{name}' => $name]);

        $times = 0;
        $lines = '';

        foreach ($rows as $row) {
            $times += (int) $row->times;
            $ticket = $row->ticket;

            if (blank($ticket)) {
                continue;
            }

            $lines .= strtr((string) Arr::get($report, 'PERSONAL_LINE'), [
                '{icon}'     => (string) Arr::get($statusIcons, (int) $ticket->status, '•'),
                '{question}' => $this->noticeText->shorten($ticket->question, (int) Arr::get($report, 'QUESTION_CHARS')),
                '{group}'    => filled($ticket->group) ? (string) $ticket->group->title : '-',
                '{times}'    => (int) $row->times,
                '{status}'   => (string) Arr::get($statusLabels, (int) $ticket->status, ''),
            ]);
        }

        /*
         * 單全被刪掉時只剩標題，那樣的訊息沒有意義 —— 改成發「昨天沒事」那份。
         * 對收件人來說結果一樣（他確實沒有要處理的東西）。
         */
        if (blank($lines)) {
            return $this->buildClearText($date, $name, false);
        }

        $text .= strtr((string) Arr::get($report, 'PERSONAL_SUMMARY'), [
            '{date}'    => $this->noticeText->date($date),
            '{tickets}' => count($rows),
            '{times}'   => $times,
        ]);
        return $text . $lines;
    }

    /**
     * 組整則統計
     *
     * @param string $date Y-m-d
     * @return string
     */
    private function buildText($type, array $range)
    {
        $report = (array) config('constants.AUTO_REPLY.REMIND.REPORT');
        $period = $this->periodWord($type);
        $data = $this->collect($type, $range);
        $tickets = Arr::get($data, 'tickets');

        $text = strtr((string) Arr::get($report, 'HEADER'), [
            '{title}' => $this->title($type),
            '{range}' => $this->rangeText($report, $type, $range),
        ]);

        if (blank($tickets)) {
            return $text . strtr((string) Arr::get($report, 'EMPTY'), [
                '{period}' => $period,
                '{praise}' => $this->encouragement->forTeam($period),
            ]);
        }

        $text .= $this->buildSummary($report, $tickets);
        $text .= $this->buildByTicket($report, $tickets);
        $text .= $this->buildByUser($report, (array) Arr::get($data, 'users'), (array) Arr::get($data, 'names'));

        return $text;
    }

    /**
     * 把這一期的統計撈出來（結構化，還沒排版）
     *
     * ⚠ **Telegram 訊息與後台報表頁走同一支。** 各查各的話，後台看到的數字
     * 會跟主管手機上那份對不起來 —— 那種不一致最難查，因為兩邊各自都「對」。
     *
     * ⚠ 這裡**不套 `MAX_LINES`**。那是 Telegram 單則 4096 字的限制，
     * 不是統計本身的性質；後台表格要的是全部。截斷由 `buildByTicket()` 與
     * `buildByUser()` 各自處理。
     *
     * ⚠ `users` 已經濾掉主管以上（見 `withoutManagers()`），`tickets` 沒有濾 ——
     * 題目不屬於任何人。
     *
     * @param string $type  self::TYPE_*
     * @param array  $range start / end
     * @return array tickets / users / names
     */
    public function collect($type, array $range)
    {
        $start = Arr::get($range, 'start');
        $end = Arr::get($range, 'end');

        $tickets = $this->remindRepository->getTicketsForRange($start, $end);
        $rows = $this->remindRepository->countByUserForRange($start, $end);
        $users = $this->usersOf($rows);

        return [
            'tickets' => $tickets,
            'users'   => $this->withoutManagers($rows, $users),
            'names'   => $this->nicknamesOf($users),
        ];
    }

    /**
     * 後台報表頁要的資料（含總計與期間）
     *
     * ⚠ 跟 Telegram 那份是**同一組數字**，差別只在這裡回陣列、那裡回字串。
     *
     * @param string $type
     * @param array  $range
     * @return array
     */
    public function forPage($type, array $range)
    {
        $report = (array) config('constants.AUTO_REPLY.REMIND.REPORT');
        $data = $this->collect($type, $range);
        $tickets = Arr::get($data, 'tickets');
        $names = (array) Arr::get($data, 'names');
        $statusLabels = (array) Arr::get($report, 'STATUS_LABEL');
        $managerStage = (int) config('constants.AUTO_REPLY.REMIND.STAGE.MANAGER');

        $times = 0;
        $escalated = 0;
        $byTicket = [];

        foreach ($tickets as $row) {
            $times += (int) $row->times;

            if ((int) $row->max_stage >= $managerStage) {
                $escalated++;
            }

            $ticket = $row->ticket;

            // 單被刪掉時 ticket 關聯會是 null —— 略過而不是印一列空白
            if (blank($ticket)) {
                continue;
            }

            $byTicket[] = [
                'question' => (string) $ticket->question,
                'group'    => filled($ticket->group) ? (string) $ticket->group->title : '-',
                'times'    => (int) $row->times,
                'status'   => (int) $ticket->status,
                'status_label' => (string) Arr::get($statusLabels, (int) $ticket->status, ''),
            ];
        }

        $byUser = [];

        foreach ((array) Arr::get($data, 'users') as $row) {
            $byUser[] = [
                'user_id' => filled($row->user_id) ? (int) $row->user_id : null,
                // user_id 為 null 代表「那次提醒沒 tag 到任何人」，前端要看得出來
                'name'    => filled($row->user_id)
                    ? (string) Arr::get($names, (int) $row->user_id, (string) Arr::get($report, 'UNKNOWN_USER'))
                    : null,
                'times'   => (int) $row->times,
                'tickets' => (int) $row->tickets,
            ];
        }

        return [
            'type'  => $type,
            'range' => $range,
            'summary' => [
                'tickets'     => count($tickets),
                'times'       => $times,
                'escalated'   => $escalated,
                'escalate_at' => (int) config('constants.AUTO_REPLY.REMIND.ESCALATE_AT'),
            ],
            'by_ticket' => $byTicket,
            'by_user'   => $byUser,
        ];
    }

    /**
     * 給 Controller 算區間用（`resolveRange()` 是 private）
     *
     * @param string      $type
     * @param string|null $endsOn
     * @return array start / end
     */
    public function rangeFor($type, $endsOn = null)
    {
        return $this->resolveRange($type, filled($endsOn) ? $endsOn : now()->toDateString());
    }

    /**
     * 總計那一段
     *
     * @param array    $report
     * @param iterable $tickets
     * @return string
     */
    private function buildSummary(array $report, $tickets)
    {
        $managerStage = (int) config('constants.AUTO_REPLY.REMIND.STAGE.MANAGER');
        $times = 0;
        $escalated = 0;

        foreach ($tickets as $row) {
            $times += (int) $row->times;

            // max_stage 已經是那張單當天最高的階段，所以「>=」就是升級過
            if ((int) $row->max_stage >= $managerStage) {
                $escalated++;
            }
        }

        return strtr((string) Arr::get($report, 'SUMMARY'), [
            '{tickets}'     => count($tickets),
            '{times}'       => $times,
            '{escalated}'   => $escalated,
            // 用 config 的門檻而不是寫死 3 —— 改設定時這句話要跟著對
            '{escalate_at}' => (int) config('constants.AUTO_REPLY.REMIND.ESCALATE_AT'),
        ]);
    }

    /**
     * 按單那一段
     *
     * @param array    $report
     * @param iterable $tickets
     * @return string
     */
    private function buildByTicket(array $report, $tickets)
    {
        $statusLabels = (array) Arr::get($report, 'STATUS_LABEL');
        $statusIcons = (array) Arr::get($report, 'STATUS_ICON');
        $max = (int) Arr::get($report, 'MAX_LINES');
        $text = (string) Arr::get($report, 'BY_TICKET_TITLE');
        $shown = 0;

        foreach ($tickets as $row) {
            if ($shown >= $max) {
                break;
            }

            $ticket = $row->ticket;

            /*
             * 單被刪掉（cascade 會連提醒紀錄一起刪，所以理論上撈不到）
             * 或 group 關聯查不到時，略過而不是印一行空白。
             */
            if (blank($ticket)) {
                continue;
            }

            $text .= strtr((string) Arr::get($report, 'BY_TICKET_LINE'), [
                '{icon}'     => (string) Arr::get($statusIcons, (int) $ticket->status, '•'),
                '{question}' => $this->noticeText->shorten($ticket->question, (int) Arr::get($report, 'QUESTION_CHARS')),
                '{group}'    => filled($ticket->group) ? (string) $ticket->group->title : '-',
                '{times}'    => (int) $row->times,
                '{status}'   => (string) Arr::get($statusLabels, (int) $ticket->status, ''),
            ]);
            $shown++;
        }

        if (count($tickets) > $shown) {
            $text .= strtr((string) Arr::get($report, 'MORE_LINE'), [
                '{count}' => count($tickets) - $shown,
            ]);
        }

        return $text;
    }

    /**
     * 按人那一段
     *
     * ⚠ 暱稱另外查，不在統計查詢裡 join —— 帳號被刪時 `user_id` 會變 null
     * （nullOnDelete），join 進來那幾列會整個從統計消失。
     *
     * ⚠ **主管以上不列進這一段**（需求方 2026-10-10）。他們出現在這裡是
     * 升級機制的副作用：第三次以後的提醒會連主管與老闆一起 tag，於是他們的
     * 次數跟著累加 —— 但「誰該去回那張單」從來不是他們。
     * 一份要給主管看的統計，列著主管自己被催了幾次，只會干擾判讀。
     *
     * ⚠ 過濾主管、撈暱稱都在 `collect()` 做好了，這裡只負責排版與截斷 ——
     * 後台報表頁走同一份資料，差別只在它不截斷。
     *
     * @param array $report
     * @param array $rows  已經濾掉主管以上的統計列
     * @param array $names user_id => 暱稱
     * @return string
     */
    private function buildByUser(array $report, array $rows, array $names)
    {
        // 整期只催到主管（例如當班的人沒設 Telegram）時這一段就整段不出現
        if (blank($rows)) {
            return '';
        }

        $max = (int) Arr::get($report, 'MAX_LINES');
        $text = (string) Arr::get($report, 'BY_USER_TITLE');
        $shown = 0;

        foreach ($rows as $row) {
            if ($shown >= $max) {
                break;
            }

            // user_id 是 null —— 那次提醒沒 tag 到任何人，單獨一行點出來
            if (blank($row->user_id)) {
                $text .= strtr((string) Arr::get($report, 'NO_TARGET_LINE'), [
                    '{times}' => (int) $row->times,
                ]);
                $shown++;

                continue;
            }

            $text .= strtr((string) Arr::get($report, 'BY_USER_LINE'), [
                '{name}'    => (string) Arr::get($names, (int) $row->user_id, (string) Arr::get($report, 'UNKNOWN_USER')),
                '{times}'   => (int) $row->times,
                '{tickets}' => (int) $row->tickets,
            ]);
            $shown++;
        }

        if (count($rows) > $shown) {
            $text .= strtr((string) Arr::get($report, 'MORE_LINE'), [
                '{count}' => count($rows) - $shown,
            ]);
        }

        return $text;
    }

    /**
     * user_id => User（從統計列挖出 id 一次撈完）
     *
     * ⚠ 一次撈完，不要在迴圈裡逐人查 —— 那就是 N+1。
     *
     * 回的是整個 User 而不只是暱稱：除了印名字，還要看 `level` 決定
     * 這個人要不要列進統計（見 withoutManagers()）。
     *
     * @param iterable $rows 統計列，每列帶 user_id
     * @return array<int, \App\Models\User>
     */
    private function usersOf($rows)
    {
        $ids = [];

        foreach ($rows as $row) {
            if (filled($row->user_id)) {
                $ids[] = (int) $row->user_id;
            }
        }

        $users = [];

        foreach ($this->userRepository->getNamesByIds(array_unique($ids)) as $user) {
            $users[(int) $user->id] = $user;
        }

        return $users;
    }

    /**
     * 濾掉主管以上的統計列
     *
     * `level` 越小官越大（ADMIN 0 / BOSS 1 / LEADER 2 / ENGINEER 3 / CS 4），
     * 所以「主管以上」是 `level <= LEADER`，留下來的是工程與客服。
     *
     * @param iterable                        $rows
     * @param array<int, \App\Models\User>    $users user_id => User
     * @return array 留下來的統計列
     */
    private function withoutManagers($rows, array $users)
    {
        $leader = (int) config('constants.USER.LEVEL.LEADER');
        $kept = [];

        foreach ($rows as $row) {
            // user_id 是 null 的那一列是「這次提醒沒 tag 到任何人」，要留著
            if (blank($row->user_id)) {
                $kept[] = $row;

                continue;
            }

            $user = Arr::get($users, (int) $row->user_id);

            /*
             * ⚠ 查不到的（帳號已刪）要**留著**，不能當成主管濾掉 ——
             * 不知道他是誰就沉默地少掉一列，統計會對不起來。
             */
            if (blank($user) || (int) $user->level > $leader) {
                $kept[] = $row;
            }
        }

        return $kept;
    }

    /**
     * user_id => 暱稱
     *
     * @param array<int, \App\Models\User> $users
     * @return array<int, string>
     */
    private function nicknamesOf(array $users)
    {
        $names = [];

        foreach ($users as $id => $user) {
            $names[(int) $id] = $user->nickname;
        }

        return $names;
    }

}

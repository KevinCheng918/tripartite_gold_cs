<?php

namespace App\Services;

use App\Repositories\AutoReplyTicketRemindRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;

/**
 * 每日超時提醒統計
 *
 * 前一天的超時提醒，兩種收件人、內容不一樣（需求方 2026-10-06）：
 *
 * | 收件人 | 內容 |
 * |---|---|
 * | 設定頁勾選的人（主管以上，可多位） | **全部人的**：按單 ＋ 按人兩段 |
 * | 昨天被提醒到的每個人 | **只有自己那份**：我被催了哪幾題（純統計，不交辦） |
 *
 * ⚠ 按單與按人**不能互相換算**：當班人員會隨時段換人，同一張單催五次可能
 * tag 到三組不同的人，所以每次 tag 到誰在 `auto_reply_ticket_remind` 逐筆落地。
 *
 * ⚠ **沒有任何提醒的日子，完整版也要發**（寫「昨天沒有超時」）。安靜不動時
 * 分不出「昨天沒事」還是「排程壞了」—— 理由同 `ShiftNoticeService`。
 * 個人版相反：沒被提醒到的人不發，他不需要收到一則「您昨天沒事」。
 */
class RemindReportService
{
    private $remindRepository;
    private $userRepository;
    private $staffDm;
    private $appSettingService;

    public function __construct(
        AutoReplyTicketRemindRepository $remindRepository,
        UserRepository $userRepository,
        StaffDmService $staffDm,
        AppSettingService $appSettingService
    ) {
        $this->remindRepository = $remindRepository;
        $this->userRepository = $userRepository;
        $this->staffDm = $staffDm;
        $this->appSettingService = $appSettingService;
    }

    /**
     * 跑一輪：完整版給勾選的人，個人版給每個被提醒到的人
     *
     * @param string|null $date   統計哪一天（預設昨天）
     * @param bool        $dryRun 只組內容不發送
     * @return array 結果，給 Command 顯示
     */
    public function run($date = null, $dryRun = false)
    {
        // 預設昨天：早上發的是「昨天一整天」的結果，今天的還在發生
        $date = filled($date) ? $date : now()->subDay()->toDateString();

        $fullText = $this->buildText($date);
        $full = $this->sendFull($fullText, $dryRun);
        $personal = $this->sendPersonal($date, $dryRun);

        return [
            'date'          => $date,
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
     * ⚠ **一個人失敗不能讓整批停掉**：`sendToUserIds()` 是逐人送的，
     * 但這裡每個人的內容都不一樣，所以是逐人呼叫、逐人收結果。
     *
     * @param string $date
     * @param bool   $dryRun
     * @return array sent / sample / failed
     */
    private function sendPersonal($date, $dryRun)
    {
        $byUser = $this->groupByUser($this->remindRepository->getUserTicketsForDate($date));

        if (blank($byUser)) {
            return ['sent' => 0, 'sample' => null, 'failed' => []];
        }

        $names = $this->nicknamesOfIds(array_keys($byUser));
        $sent = 0;
        $failed = [];
        $sample = null;

        foreach ($byUser as $userId => $rows) {
            $name = (string) Arr::get($names, (int) $userId, '');
            $text = $this->buildPersonalText($date, $name, $rows);
            $sample = filled($sample) ? $sample : $text;

            if ($dryRun) {
                $sent++;

                continue;
            }

            $result = $this->staffDm->sendToUserIds([$userId], $text);

            if (Arr::get($result, 'sent', 0) > 0) {
                $sent++;

                continue;
            }

            $failed[] = filled($name) ? $name : "#{$userId}";
        }

        return ['sent' => $sent, 'sample' => $sample, 'failed' => $failed];
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

        $text = strtr((string) Arr::get($report, 'PERSONAL_HEADER'), [
            '{name}' => $name,
            '{date}' => $this->formatDate($date),
        ]);

        $times = 0;
        $lines = '';

        foreach ($rows as $row) {
            $times += (int) $row->times;
            $ticket = $row->ticket;

            if (blank($ticket)) {
                continue;
            }

            $lines .= strtr((string) Arr::get($report, 'PERSONAL_LINE'), [
                '{question}' => $this->shorten($ticket->question, (int) Arr::get($report, 'QUESTION_CHARS')),
                '{group}'    => filled($ticket->group) ? (string) $ticket->group->title : '-',
                '{times}'    => (int) $row->times,
                '{status}'   => (string) Arr::get($statusLabels, (int) $ticket->status, ''),
            ]);
        }

        // 單全被刪掉時只剩標題，那樣的訊息沒有意義
        if (blank($lines)) {
            return $text . (string) Arr::get($report, 'PERSONAL_NONE');
        }

        $text .= strtr((string) Arr::get($report, 'PERSONAL_SUMMARY'), [
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
    private function buildText($date)
    {
        $report = (array) config('constants.AUTO_REPLY.REMIND.REPORT');
        $tickets = $this->remindRepository->getTicketsForDate($date);

        $text = strtr((string) Arr::get($report, 'HEADER'), ['{date}' => $this->formatDate($date)]);

        if (blank($tickets)) {
            return $text . (string) Arr::get($report, 'EMPTY');
        }

        $text .= $this->buildSummary($report, $tickets);
        $text .= $this->buildByTicket($report, $tickets);
        $text .= $this->buildByUser($report, $date);

        return $text;
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
            '{tickets}'   => count($tickets),
            '{times}'     => $times,
            '{escalated}' => $escalated,
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
                '{question}' => $this->shorten($ticket->question, (int) Arr::get($report, 'QUESTION_CHARS')),
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
     * @param array  $report
     * @param string $date
     * @return string
     */
    private function buildByUser(array $report, $date)
    {
        $rows = $this->remindRepository->countByUserForDate($date);

        if (blank($rows)) {
            return '';
        }

        $names = $this->nicknamesOf($rows);
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
     * user_id => 暱稱（從統計列挖出 id）
     *
     * @param iterable $rows
     * @return array
     */
    private function nicknamesOf($rows)
    {
        $ids = [];

        foreach ($rows as $row) {
            if (filled($row->user_id)) {
                $ids[] = (int) $row->user_id;
            }
        }

        return $this->nicknamesOfIds($ids);
    }

    /**
     * user_id => 暱稱
     *
     * ⚠ 一次撈完，不要在迴圈裡逐人查 —— 那就是 N+1。
     *
     * @param array $ids
     * @return array
     */
    private function nicknamesOfIds(array $ids)
    {
        $names = [];

        foreach ($this->userRepository->getNamesByIds(array_unique($ids)) as $user) {
            $names[(int) $user->id] = $user->nickname;
        }

        return $names;
    }

    /**
     * 截短並補省略號
     *
     * @param string $text
     * @param int    $chars
     * @return string
     */
    private function shorten($text, $chars)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));

        if ($chars < 1 || mb_strlen($text) <= $chars) {
            return $text;
        }

        return mb_substr($text, 0, $chars) . '…';
    }

    /**
     * `2026-10-05` → `10/5（週日）`
     *
     * @param string $date
     * @return string
     */
    private function formatDate($date)
    {
        $weekdays = ['日', '一', '二', '三', '四', '五', '六'];
        $carbon = \Illuminate\Support\Carbon::parse($date);

        return $carbon->format('n/j') . '（週' . Arr::get($weekdays, (int) $carbon->dayOfWeek, '') . '）';
    }
}

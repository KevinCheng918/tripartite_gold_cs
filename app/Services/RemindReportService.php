<?php

namespace App\Services;

use App\Repositories\AutoReplyTicketRemindRepository;
use App\Repositories\UserRepository;
use App\Services\Notify\EncouragementWriter;
use App\Services\Notify\NoticeText;
use Illuminate\Support\Arr;

/**
 * 每日超時提醒統計
 *
 * 前一天的超時提醒，兩種收件人、內容不一樣（需求方 2026-10-06）：
 *
 * | 收件人 | 內容 |
 * |---|---|
 * | 設定頁勾選的人（主管以上，可多位） | **全部人的**：按單 ＋ 按人兩段 |
 * | **所有在職同仁** | 自己那份：被催到就是統計，沒被催到就是一句肯定 |
 *
 * ⚠ 按單與按人**不能互相換算**：當班人員會隨時段換人，同一張單催五次可能
 * tag 到三組不同的人，所以每次 tag 到誰在 `auto_reply_ticket_remind` 逐筆落地。
 *
 * ⚠ **沒事的日子兩種都要發**（需求方 2026-10-06 調整）。安靜不動時分不出
 * 「昨天沒事」還是「排程壞了」。沒事那天的那句肯定**由模型當天生成、不用公版**
 * —— 公版寫久了大家會自動略過，那就失去意義了。
 * 模型不可用時才退公版：沒有鼓勵的話，比整則不發好得多。
 */
class RemindReportService
{
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
    public function test()
    {
        $date = now()->subDay()->toDateString();
        $text = (string) config('constants.AUTO_REPLY.REMIND.REPORT.TEST_PREFIX') . $this->buildText($date);

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
        $byUser = $this->groupByUser($this->remindRepository->getUserTicketsForDate($date));

        /*
         * ⚠ **發給所有在職同仁，不只昨天被提醒到的人**（需求方 2026-10-06）。
         *
         * 沒被提醒到的人收到的是一句肯定，不是空白統計 —— 所以收件人範圍是
         * 「同仁」而不是「昨天出事的人」。`getDmCandidates()` 已經排除管理者
         * 與停用帳號，跟設定頁的收件人清單同一份名單。
         */
        $sent = 0;
        $failed = [];
        $sample = null;

        foreach ($this->userRepository->getDmCandidates() as $user) {
            $userId = (int) $user->id;
            $rows = (array) Arr::get($byUser, $userId, []);

            /*
             * 有被提醒到 → 發統計；沒有 → 發一句肯定。
             *
             * ⚠ 肯定那句是逐人叫模型生成的（每次幾秒），所以**空跑時不要生**：
             * `--dry-run` 只是要看排版，不該為了預覽燒掉額度也不該跑好幾分鐘。
             */
            $text = filled($rows)
                ? $this->buildPersonalText($date, (string) $user->nickname, $rows)
                : $this->buildClearText((string) $user->nickname, $dryRun);

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
     * @param string $name
     * @param bool   $dryRun 空跑時不叫模型，直接用公版
     * @return string
     */
    private function buildClearText($name, $dryRun)
    {
        $report = (array) config('constants.AUTO_REPLY.REMIND.REPORT');
        $praise = $dryRun
            ? (string) Arr::get($report, 'ENCOURAGE.PERSON_FALLBACK')
            : $this->encouragement->forPerson($name);

        return strtr((string) Arr::get($report, 'PERSONAL_CLEAR'), [
            '{name}'   => $name,
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

        $text = strtr((string) Arr::get($report, 'PERSONAL_HEADER'), [
            '{name}' => $name,
            '{date}' => $this->noticeText->date($date),
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
            return $this->buildClearText($name, false);
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

        $text = strtr((string) Arr::get($report, 'HEADER'), ['{date}' => $this->noticeText->date($date)]);

        if (blank($tickets)) {
            return $text . strtr((string) Arr::get($report, 'EMPTY'), [
                '{praise}' => $this->encouragement->forTeam(),
            ]);
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

}

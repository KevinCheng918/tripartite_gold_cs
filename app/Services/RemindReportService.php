<?php

namespace App\Services;

use App\Repositories\AutoReplyTicketRemindRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;

/**
 * 每日超時提醒統計
 *
 * 前一天所有的超時提醒，整理成一則私訊給設定頁指定的收件人。
 * 兩個維度都要（需求方 2026-10-06）：
 *
 * - **按單**：哪一題卡住、被催幾次、現在的狀態
 * - **按人**：誰被催得最多
 *
 * ⚠ 這兩個維度**不能互相換算**：當班人員會隨時段換人，同一張單催五次可能
 * tag 到三組不同的人，所以每次 tag 到誰在 `auto_reply_ticket_remind` 逐筆落地。
 *
 * ⚠ **沒有任何提醒的日子也要發**。安靜不動時分不出「昨天沒事」還是
 * 「排程壞了」—— 理由同 `ShiftNoticeService`。
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
     * 跑一輪
     *
     * @param string|null $date   統計哪一天（預設昨天）
     * @param bool        $dryRun 只組內容不發送
     * @return array date / text / sent / reason / name
     */
    public function run($date = null, $dryRun = false)
    {
        // 預設昨天：早上發的是「昨天一整天」的結果，今天的還在發生
        $date = filled($date) ? $date : now()->subDay()->toDateString();
        $text = $this->buildText($date);

        if ($dryRun) {
            return ['date' => $date, 'text' => $text, 'sent' => false, 'reason' => null, 'name' => null];
        }

        $result = $this->staffDm->sendToUserId(
            $this->appSettingService->getInt(AppSettingService::KEY_REMIND_REPORT_MANAGER),
            $text
        );

        return [
            'date'   => $date,
            'text'   => $text,
            'sent'   => Arr::get($result, 'sent', false),
            'reason' => Arr::get($result, 'reason'),
            'name'   => Arr::get($result, 'name'),
        ];
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
     * user_id => 暱稱
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

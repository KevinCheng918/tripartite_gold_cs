<?php

namespace App\Services;

use App\Repositories\AutoReplyTicketRepository;
use App\Repositories\ShiftAssignmentRepository;
use App\Repositories\ShiftRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 早上 7:00 的待接手清單
 *
 * 把「還在等人回覆」的求助單發到**內部群組**，並 tag 當天早班。
 *
 * ⚠ **為什麼需要這支**：大夜班目前沒有人排班，所以深夜的超時提醒
 * （`AutoReplySupportService::remindTimeoutTickets()`）tag 不到任何人，
 * 那些問題整晚沒有人接手。這則在早班上班前把它們正式交接出去。
 *
 * ⚠ 發**群組**不是私訊（需求方 2026-10-06 指定）：交接要留在大家看得到的
 * 地方，而且 tag 得到人就不必擔心對方有沒有私訊過 bot。
 */
class TicketHandoverService
{
    /** @var string 跳過原因：沒設定內部支援群組 */
    const SKIP_NO_GROUP = 'no_group';

    /** @var string 跳過原因：Telegram 送出失敗 */
    const SKIP_SEND_FAILED = 'send_failed';

    private $ticketRepository;
    private $assignmentRepository;
    private $shiftRepository;
    private $userRepository;
    private $supportGroup;

    public function __construct(
        AutoReplyTicketRepository $ticketRepository,
        ShiftAssignmentRepository $assignmentRepository,
        ShiftRepository $shiftRepository,
        UserRepository $userRepository,
        SupportGroupService $supportGroup
    ) {
        $this->ticketRepository = $ticketRepository;
        $this->assignmentRepository = $assignmentRepository;
        $this->shiftRepository = $shiftRepository;
        $this->userRepository = $userRepository;
        $this->supportGroup = $supportGroup;
    }

    /**
     * 跑一輪
     *
     * @param string|null $date   哪一天的早班（預設今天）
     * @param bool        $dryRun 只組訊息不發送
     * @return array date / text / sent / reason / pending / mentioned
     */
    public function run($date = null, $dryRun = false)
    {
        $date = filled($date) ? $date : now()->toDateString();

        $tickets = $this->ticketRepository->getPendingTickets();
        $users = $this->morningShiftUsers($date);
        $text = $this->buildText($date, $tickets, $users);

        $result = [
            'date'      => $date,
            'text'      => $text,
            'pending'   => count($tickets),
            'mentioned' => collect($users)->pluck('nickname')->all(),
            'sent'      => false,
            'reason'    => null,
        ];

        if ($dryRun) {
            return $result;
        }

        // 短路：沒設群組就不用組任何東西了（組字串便宜，但 log 要講清楚原因）
        if (!$this->supportGroup->isConfigured()) {
            Log::warning('待接手清單沒發出去：未設定內部支援群組');

            return array_merge($result, ['reason' => self::SKIP_NO_GROUP]);
        }

        if ($this->send($text)) {
            return array_merge($result, ['sent' => true]);
        }

        return array_merge($result, ['reason' => self::SKIP_SEND_FAILED]);
    }

    /**
     * 當天早班的人
     *
     * ⚠ **「早班」＝啟用中的班別裡 `start_time` 最早的那一個**，不是比對班別名稱。
     * 比對名稱的話，哪天班別改名（「早班」→「A 班」）就會悄悄 tag 不到人而且
     * 不報錯。代價是：如果大夜班被改成從 00:00 開始，它會變成「最早」——
     * 真的要改班別時間的話，這裡要一起看。
     *
     * @param string $date
     * @return \Illuminate\Database\Eloquent\Collection|array
     */
    private function morningShiftUsers($date)
    {
        $shift = $this->earliestShift();

        if (blank($shift)) {
            return [];
        }

        $userIds = [];

        foreach ($this->assignmentRepository->getByDateRange($date, $date) as $assignment) {
            if ((int) $assignment->shift_id === (int) $shift->id) {
                $userIds[] = (int) $assignment->user_id;
            }
        }

        if (blank($userIds)) {
            return [];
        }

        // getMentionableByIds 會濾掉工程與沒填 Telegram 帳號的人
        return $this->userRepository->getMentionableByIds(array_unique($userIds));
    }

    /**
     * 啟用中、上班時間最早的那個班別
     *
     * @return object|null
     */
    private function earliestShift()
    {
        $earliest = null;

        foreach ($this->shiftRepository->allActive() as $shift) {
            if (blank($shift->start_time)) {
                continue;
            }

            if (blank($earliest) || $shift->start_time < $earliest->start_time) {
                $earliest = $shift;
            }
        }

        return $earliest;
    }

    /**
     * 組整則訊息
     *
     * @param string   $date
     * @param iterable $tickets
     * @param iterable $users
     * @return string
     */
    private function buildText($date, $tickets, $users)
    {
        $handover = (array) config('constants.SHIFT_HANDOVER');

        $text = strtr((string) Arr::get($handover, 'HEADER'), [
            '{date}'     => $this->formatDate($date),
            '{mentions}' => $this->buildMentions($handover, $users),
        ]);

        if (blank($tickets)) {
            return $text . (string) Arr::get($handover, 'EMPTY');
        }

        $text .= strtr((string) Arr::get($handover, 'SUMMARY'), ['{count}' => count($tickets)]);

        $max = (int) Arr::get($handover, 'MAX_LINES');
        $shown = 0;

        foreach ($tickets as $ticket) {
            if ($shown >= $max) {
                break;
            }

            $text .= strtr((string) Arr::get($handover, 'LINE'), [
                '{question}' => $this->shorten($ticket->question, (int) Arr::get($handover, 'QUESTION_CHARS')),
                '{group}'    => filled($ticket->group) ? (string) $ticket->group->title : '-',
                '{waited}'   => $this->waited($handover, $ticket->created_at),
            ]);
            $shown++;
        }

        if (count($tickets) > $shown) {
            $text .= strtr((string) Arr::get($handover, 'MORE_LINE'), [
                '{count}' => count($tickets) - $shown,
            ]);
        }

        return $text;
    }

    /**
     * 組 @ 字串
     *
     * tag 不到人時換成一句說明 —— 那代表這份清單沒有 owner，
     * 靜靜發出去只會沒人認領。
     *
     * @param array    $handover
     * @param iterable $users
     * @return string
     */
    private function buildMentions(array $handover, $users)
    {
        if (blank($users)) {
            return (string) Arr::get($handover, 'NO_MENTION');
        }

        $mentions = [];

        foreach ($users as $user) {
            $mentions[] = '@' . ltrim($user->telegram_username, '@');
        }

        return implode(' ', $mentions);
    }

    /**
     * 等了多久（天／小時／分鐘，只取最大的兩個單位）
     *
     * @param array  $handover
     * @param mixed  $createdAt
     * @return string
     */
    private function waited(array $handover, $createdAt)
    {
        if (blank($createdAt)) {
            return '-';
        }

        $minutes = (int) now()->diffInMinutes($createdAt);
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);

        if ($days > 0) {
            return strtr((string) Arr::get($handover, 'WAITED_DAYS'), [
                '{days}'  => $days,
                '{hours}' => $hours,
            ]);
        }

        if ($hours > 0) {
            return strtr((string) Arr::get($handover, 'WAITED_HOURS'), [
                '{hours}'   => $hours,
                '{minutes}' => $minutes % 60,
            ]);
        }

        return strtr((string) Arr::get($handover, 'WAITED_MINS'), ['{minutes}' => $minutes]);
    }

    /**
     * 送出
     *
     * ⚠ 失敗不丟例外：這支掛在排程上，丟例外只會讓整輪排程中斷。
     *
     * @param string $text
     * @return bool
     */
    private function send($text)
    {
        try {
            $result = $this->supportGroup->send($text);

            if (filled(Arr::get((array) $result, 'result'))) {
                return true;
            }

            Log::warning('待接手清單未送達', ['response' => $result]);

            return false;
        } catch (\Exception $e) {
            Log::error('待接手清單送出失敗', ['error' => $e->getMessage()]);

            return false;
        }
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
     * `2026-10-06` → `10/6（週二）`
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

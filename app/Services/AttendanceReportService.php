<?php

namespace App\Services;

use App\Repositories\LeaveRequestRepository;
use App\Repositories\UserRepository;
use App\Services\Notify\EncouragementWriter;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * 打卡週報表／月報表通知
 *
 * 兩種收件人，內容不一樣（比照 `TaskNoticeService`、`RemindReportService`）：
 *
 * | 收件人 | 內容 |
 * |---|---|
 * | 設定頁勾選的人（基本上主管以上） | 全部人的：每人一行 |
 * | 其餘在職同仁 | 自己那份 |
 *
 * ⚠ **勾了完整版的人不再收個人版**（需求方 2026-10-10，跟既有三個通知一致）
 * —— 完整版的「依人員」裡已經有他自己，兩則同時間到看起來像重複發送。
 *
 * ⚠ **統計本體不在這裡**。`AttendanceService::getReport()` 早就算好了
 * （打卡出勤頁用的就是那一支），這裡只負責挑收件人、組文案、發送。
 *
 * ⚠ **`getReport()` 只回「期間內有打卡紀錄的人」**。整段期間都在請假、
 * 或根本沒排班的人不會出現 —— 所以這裡從同仁名單出發，查不到就當全部是 0，
 * 請假資料另外從 `LeaveRequestRepository` 撈（否則「整週都請假」的人
 * 會收到一則空白報表）。
 */
class AttendanceReportService
{
    /** @var string 週報 */
    const TYPE_WEEKLY = 'weekly';

    /** @var string 月報 */
    const TYPE_MONTHLY = 'monthly';

    private $attendanceService;
    private $leaveRepository;
    private $userRepository;
    private $staffDm;
    private $appSettingService;
    private $encouragement;

    public function __construct(
        AttendanceService $attendanceService,
        LeaveRequestRepository $leaveRepository,
        UserRepository $userRepository,
        StaffDmService $staffDm,
        AppSettingService $appSettingService,
        EncouragementWriter $encouragement
    ) {
        $this->attendanceService = $attendanceService;
        $this->leaveRepository = $leaveRepository;
        $this->userRepository = $userRepository;
        $this->staffDm = $staffDm;
        $this->appSettingService = $appSettingService;
        $this->encouragement = $encouragement;
    }

    /**
     * 跑一輪
     *
     * @param string      $type   self::TYPE_*
     * @param string|null $endsOn 以哪一天往回推區間（預設今天）
     * @param bool        $dryRun 只組內容不發送
     * @return array 結果，給 Command 顯示
     */
    public function run($type, $endsOn = null, $dryRun = false)
    {
        $range = $this->resolveRange($type, filled($endsOn) ? $endsOn : now()->toDateString());
        $stats = $this->collect($range);

        $managerText = $this->buildManagerText($type, $range, $stats);
        $manager = $this->sendManager($managerText, $dryRun);
        $personal = $this->sendPersonal($type, $range, $stats, $dryRun);

        return [
            'type'          => $type,
            'range'         => $range,
            'people'        => count($stats),
            'manager_text'  => $managerText,
            'manager_sent'  => Arr::get($manager, 'sent', 0),
            'manager_skip'  => Arr::get($manager, 'reason'),
            'manager_names' => Arr::get($manager, 'names', []),
            'personal_sent' => Arr::get($personal, 'sent', 0),
            'personal_text' => Arr::get($personal, 'sample'),
            'failed'        => array_merge(
                Arr::get($manager, 'failed', []),
                Arr::get($personal, 'failed', [])
            ),
        ];
    }

    /**
     * 測試發送：真的把上一期的完整報表私訊給勾選的人
     *
     * ⚠ 刻意**不是** dry-run，理由同 `ShiftNoticeService::test()`：沒綁定、
     * 被封鎖這些真正會出事的狀況，只組字串測不到。
     *
     * ⚠ **只發完整版**，不會打擾每一位同仁。
     *
     * @param string $type
     * @return array sent / reason / names / failed
     */
    public function test($type)
    {
        $range = $this->resolveRange($type, now()->toDateString());
        $stats = $this->collect($range);

        $text = (string) config('constants.ATTENDANCE_REPORT.TEST_PREFIX')
            . $this->buildManagerText($type, $range, $stats);

        return $this->sendManager($text, false);
    }

    // ---------------------------------------------------------------
    //  區間與統計
    // ---------------------------------------------------------------

    /**
     * 給 Controller 算區間用（`resolveRange()` 是 private）
     *
     * ⚠ 後台報表頁跟通知走同一支，週／月的定義才不會有兩套。
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
     * 後台報表頁要的資料
     *
     * ⚠ 走 `collect()` 而不是直接問 `AttendanceService::getReport()` ——
     * 後者只回「這段期間有打卡紀錄的人」，整期都請假的人會整列消失。
     * 主管在 Telegram 看得到那一列、在後台看不到，那種不一致最難解釋。
     *
     * ⚠ 只挑 id 與暱稱吐出去。`$stat['user']` 是整個 User Model，直接丟進
     * JSON 會把 telegram_user_id 這類東西一起送到前端。
     *
     * @param array $range start / end
     * @return array[] user_id / name / 各項統計
     */
    public function forPage(array $range)
    {
        $stats = $this->collect($range);
        $names = $this->nicknamesOf(array_keys($stats));
        $rows = [];

        foreach ($stats as $userId => $stat) {
            $userId = (int) $userId;

            $rows[] = [
                'user_id'      => $userId,
                'name'         => (string) Arr::get($names, $userId, "#{$userId}"),
                'total_days'   => (int) Arr::get($stat, 'total_days'),
                'normal_days'  => (int) Arr::get($stat, 'normal_days'),
                'late_count'   => (int) Arr::get($stat, 'late_count'),
                'late_minutes' => (int) Arr::get($stat, 'late_total_minutes'),
                'early_count'  => (int) Arr::get($stat, 'early_count'),
                'early_minutes' => (int) Arr::get($stat, 'early_total_minutes'),
                'absent_count' => (int) Arr::get($stat, 'absent_count'),
                'amend_count'  => (int) Arr::get($stat, 'amend_count'),
                'leave_days'   => (float) Arr::get($stat, 'leave_days'),
                'leave_hours'  => (float) Arr::get($stat, 'leave_hours'),
                'overtime_minutes' => (int) Arr::get($stat, 'overtime_total_minutes'),
            ];
        }

        return $rows;
    }

    /**
     * 算出要統計哪一段
     *
     * ⚠ 都是「上一期」：週一 11:00 發的是**上週**一～日，1 號 11:00 發的是
     * **上個月**。當期還沒過完，統計沒有意義。
     *
     * ⚠ 週的起點用 `startOfWeek()`，Carbon 預設週一開始，正好符合需求
     * （「禮拜一到禮拜日」）。不要自己用 `-7 days` 推 —— 補發時（指定別的
     * 日期）那樣算出來的不會對齊週一。
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

        $start = $base->copy()->startOfWeek()->subWeek();

        return ['start' => $start->toDateString(), 'end' => $start->copy()->addDays(6)->toDateString()];
    }

    /**
     * 把每個人的統計整理成 user_id => 數字
     *
     * @param array $range
     * @return array
     */
    private function collect(array $range)
    {
        $start = Arr::get($range, 'start');
        $end = Arr::get($range, 'end');

        $byUser = [];

        foreach ($this->attendanceService->getReport($start, $end) as $row) {
            $user = Arr::get($row, 'user');

            if (blank($user)) {
                continue;
            }

            $byUser[(int) $user->id] = $row;
        }

        /*
         * ⚠ 請假另外撈一次。`getReport()` 裡雖然也有請假統計，但它只涵蓋
         * 「有打卡紀錄的人」—— 整週都請假的人在那支的結果裡根本不存在。
         * 這裡用同一支 `summariseLeaves()` 算，兩邊數字才會一致。
         */
        $leaves = $this->leaveRepository->getApprovedByDateRange($start, $end);

        foreach ($leaves->groupBy('user_id') as $userId => $userLeaves) {
            $summary = $this->attendanceService->summariseLeaves($userLeaves, $start, $end);
            $userId = (int) $userId;

            if (!Arr::has($byUser, $userId)) {
                $byUser[$userId] = $this->emptyStat();
            }

            $byUser[$userId]['leave_count'] = Arr::get($summary, 'count');
            $byUser[$userId]['leave_days'] = Arr::get($summary, 'days');
            $byUser[$userId]['leave_hours'] = Arr::get($summary, 'hours');
            $byUser[$userId]['leave_ranges'] = Arr::get($summary, 'ranges');
        }

        return $byUser;
    }

    /**
     * 一筆都沒有的人長這樣
     *
     * @return array
     */
    private function emptyStat()
    {
        return [
            // 整期都請假的人就是這一種：一天都沒出勤，但不是曠工
            'total_days'             => 0,
            'normal_days'            => 0,
            'late_count'             => 0,
            'late_total_minutes'     => 0,
            'early_count'            => 0,
            'early_total_minutes'    => 0,
            'absent_count'           => 0,
            'overtime_total_minutes' => 0,
            'amend_count'            => 0,
            'leave_count'            => 0,
            'leave_days'             => 0,
            'leave_hours'            => 0,
            'leave_ranges'           => [],
        ];
    }

    /**
     * 這個人這段期間有沒有「出狀況」＝ 遲到／早退／曠工
     *
     * ⚠ 請假**不是狀況，但也不是全勤**。核准過的假不該讓人看起來像出了事，
     * 所以不列進這裡；但有請假就不能報「全勤」（需求方 2026-10-10 更正），
     * 那是 `managerLine()` 的第二種狀態。
     *
     * @param array $stat
     * @return bool
     */
    private function hasIssue(array $stat)
    {
        return (int) Arr::get($stat, 'late_count', 0) > 0
            || (int) Arr::get($stat, 'early_count', 0) > 0
            || (int) Arr::get($stat, 'absent_count', 0) > 0;
    }

    // ---------------------------------------------------------------
    //  完整版
    // ---------------------------------------------------------------

    /**
     * 組完整版：每個人一行
     *
     * @param string $type
     * @param array  $range
     * @param array  $stats
     * @return string
     */
    private function buildManagerText($type, array $range, array $stats)
    {
        $report = (array) config('constants.ATTENDANCE_REPORT');

        $text = strtr((string) Arr::get($report, 'MANAGER_HEADER'), [
            '{title}' => $this->title($type),
            '{range}' => $this->rangeText($report, $range),
        ]);

        if (blank($stats)) {
            return $text . (string) Arr::get($report, 'MANAGER_EMPTY');
        }

        $names = $this->nicknamesOf(array_keys($stats));
        $text .= (string) Arr::get($report, 'MANAGER_USER_TITLE');

        foreach ($stats as $userId => $stat) {
            $text .= $this->managerLine($report, (string) Arr::get($names, (int) $userId, "#{$userId}"), $stat);
        }

        return $text;
    }

    /**
     * 完整版裡某一個人那一行
     *
     * 三種狀態，Early Return 依序判斷：
     *
     * | 狀態 | 條件 |
     * |---|---|
     * | ⚠️ 有狀況 | 有遲到／早退／曠工 |
     * | 🌴 有請假 | 沒出狀況，但請過假 |
     * | ✅ 全勤 | 沒出狀況，**而且沒請假** |
     *
     * ⚠ **請假不能算全勤**（需求方 2026-10-10 更正）。請了五天假的人跟整期
     * 全到的人掛同一個標籤，主管一眼看過去分不出誰真的每天都在。
     *
     * @param array  $report
     * @param string $name
     * @param array  $stat
     * @return string
     */
    private function managerLine(array $report, $name, array $stat)
    {
        $overtime = $this->managerOvertime($report, $stat);
        $leave = $this->leaveAmount($report, $stat);

        if ($this->hasIssue($stat)) {
            // 有狀況時請假仍然要標 —— 主管要知道那幾天他本來就不在
            $extra = $overtime . (filled($leave)
                ? strtr((string) Arr::get($report, 'MANAGER_LEAVE'), ['{leave}' => $leave])
                : '');

            return strtr((string) Arr::get($report, 'MANAGER_ISSUE'), [
                '{name}'   => $name,
                '{issues}' => $this->managerIssues($report, $stat),
                '{extra}'  => $extra,
            ]);
        }

        if (filled($leave)) {
            return strtr((string) Arr::get($report, 'MANAGER_LEAVE_ONLY'), [
                '{name}'  => $name,
                '{leave}' => $leave,
                '{extra}' => $overtime,
            ]);
        }

        return strtr((string) Arr::get($report, 'MANAGER_PERFECT'), [
            '{name}'  => $name,
            '{extra}' => $overtime,
        ]);
    }

    /**
     * 完整版那一行的「遲到 N 次 X 分鐘、早退 …」
     *
     * @param array $report
     * @param array $stat
     * @return string
     */
    private function managerIssues(array $report, array $stat)
    {
        $parts = [];

        if ((int) Arr::get($stat, 'late_count', 0) > 0) {
            $parts[] = strtr((string) Arr::get($report, 'MANAGER_LATE'), [
                '{count}'   => (int) Arr::get($stat, 'late_count'),
                '{minutes}' => $this->minutes($report, (int) Arr::get($stat, 'late_total_minutes')),
            ]);
        }

        if ((int) Arr::get($stat, 'early_count', 0) > 0) {
            $parts[] = strtr((string) Arr::get($report, 'MANAGER_EARLY'), [
                '{count}'   => (int) Arr::get($stat, 'early_count'),
                '{minutes}' => $this->minutes($report, (int) Arr::get($stat, 'early_total_minutes')),
            ]);
        }

        if ((int) Arr::get($stat, 'absent_count', 0) > 0) {
            $parts[] = strtr((string) Arr::get($report, 'MANAGER_ABSENT'), [
                '{days}' => (int) Arr::get($stat, 'absent_count'),
            ]);
        }

        return implode((string) Arr::get($report, 'MANAGER_SEPARATOR'), $parts);
    }

    /**
     * 完整版那一行後面的加班（沒加班就是空字串）
     *
     * ⚠ 請假**不在這裡**：三種狀態要放的位置不一樣（有請假那一行本身就在
     * 講請假，不能再附一次），所以由 `managerLine()` 各自決定。
     *
     * @param array $report
     * @param array $stat
     * @return string
     */
    private function managerOvertime(array $report, array $stat)
    {
        if ((int) Arr::get($stat, 'overtime_total_minutes', 0) <= 0) {
            return '';
        }

        return strtr((string) Arr::get($report, 'MANAGER_OVERTIME'), [
            '{hours}' => $this->hours($report, (int) Arr::get($stat, 'overtime_total_minutes')),
        ]);
    }

    /**
     * 發完整版給設定頁勾選的人
     *
     * @param string $text
     * @param bool   $dryRun
     * @return array
     */
    private function sendManager($text, $dryRun)
    {
        $userIds = $this->appSettingService->getIntList(AppSettingService::KEY_ATTENDANCE_REPORT_MANAGER);

        if (blank($userIds)) {
            return ['sent' => 0, 'reason' => StaffDmService::SKIP_NO_RECIPIENT, 'failed' => [], 'names' => []];
        }

        // 空跑只回「幾個人會收到」，不判斷綁定狀態 —— 那是測試發送的事
        if ($dryRun) {
            return ['sent' => count($userIds), 'reason' => null, 'failed' => [], 'names' => []];
        }

        return $this->staffDm->sendToUserIds($userIds, $text);
    }

    // ---------------------------------------------------------------
    //  個人版
    // ---------------------------------------------------------------

    /**
     * 發個人版給每位在職同仁
     *
     * ⚠ **這段期間沒有任何紀錄的人也要發**（全是 0 → 一句勉勵的話）。
     * 安靜不動時分不出「這期沒事」還是「排程壞了」。
     *
     * @param string $type
     * @param array  $range
     * @param array  $stats
     * @param bool   $dryRun
     * @return array sent / sample / failed
     */
    private function sendPersonal($type, array $range, array $stats, $dryRun)
    {
        $fullRecipients = $this->appSettingService->getIntList(AppSettingService::KEY_ATTENDANCE_REPORT_MANAGER);

        $sent = 0;
        $failed = [];
        $sample = null;

        foreach ($this->userRepository->getDmCandidates() as $user) {
            $userId = (int) $user->id;

            if (in_array($userId, $fullRecipients, true)) {
                continue;
            }

            $stat = (array) Arr::get($stats, $userId, $this->emptyStat());
            $text = $this->buildPersonalText($type, $range, (string) $user->nickname, $stat, $dryRun);
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
     * 組個人版
     *
     * 需求方指定的三條規則：
     *   1. 沒有遲到／早退／曠工 → 說一句勉勵的話
     *   2. 沒有狀況但有請假 → 先說幾號到幾號請假，再說「其餘日子無…」
     *   3. 有加班 → 報加班時數
     *
     * @param string $type
     * @param array  $range
     * @param string $name
     * @param array  $stat
     * @param bool   $dryRun
     * @return string
     */
    private function buildPersonalText($type, array $range, $name, array $stat, $dryRun)
    {
        $report = (array) config('constants.ATTENDANCE_REPORT');

        $text = strtr((string) Arr::get($report, 'PERSONAL_HEADER'), [
            '{name}'  => $name,
            '{title}' => $this->title($type),
            '{range}' => $this->rangeText($report, $range),
        ]);

        $hasLeave = filled(Arr::get($stat, 'leave_ranges'));

        if ($hasLeave) {
            $text .= (string) Arr::get($report, 'PERSONAL_LEAVE_TITLE');

            foreach ((array) Arr::get($stat, 'leave_ranges') as $leaveRange) {
                $text .= strtr((string) Arr::get($report, 'PERSONAL_LEAVE_LINE'), [
                    '{range}' => $this->leaveRangeText($report, $leaveRange),
                ]);
            }
        }

        if ($this->hasIssue($stat)) {
            $text .= (string) Arr::get($report, 'PERSONAL_ISSUE_TITLE') . $this->personalIssues($report, $stat);

            return $text . $this->personalExtras($report, $stat);
        }

        // 有請假的人說「其餘日子」，沒請假的直接說「無…情形」
        $text .= (string) Arr::get($report, $hasLeave ? 'PERSONAL_CLEAN_REST' : 'PERSONAL_CLEAN');
        $text .= $this->personalExtras($report, $stat);

        /*
         * ⚠ 勉勵的話是逐人叫模型生成的（每次幾秒），所以**空跑時不要生**：
         * `--dry-run` 只是要看排版，不該為了預覽燒掉額度也不該跑好幾分鐘。
         */
        $period = $this->periodWord($type);

        $praise = $dryRun
            ? strtr((string) Arr::get($report, 'ENCOURAGE.FALLBACK'), ['{name}' => $name, '{period}' => $period])
            : $this->encouragement->forAttendance($name, $period);

        return $text . strtr((string) Arr::get($report, 'PERSONAL_PRAISE'), ['{praise}' => $praise]);
    }

    /**
     * 個人版的狀況明細
     *
     * @param array $report
     * @param array $stat
     * @return string
     */
    private function personalIssues(array $report, array $stat)
    {
        $text = '';

        if ((int) Arr::get($stat, 'late_count', 0) > 0) {
            $text .= strtr((string) Arr::get($report, 'LATE_LINE'), [
                '{count}'   => (int) Arr::get($stat, 'late_count'),
                '{minutes}' => $this->minutes($report, (int) Arr::get($stat, 'late_total_minutes')),
            ]);
        }

        if ((int) Arr::get($stat, 'early_count', 0) > 0) {
            $text .= strtr((string) Arr::get($report, 'EARLY_LINE'), [
                '{count}'   => (int) Arr::get($stat, 'early_count'),
                '{minutes}' => $this->minutes($report, (int) Arr::get($stat, 'early_total_minutes')),
            ]);
        }

        if ((int) Arr::get($stat, 'absent_count', 0) > 0) {
            $text .= strtr((string) Arr::get($report, 'ABSENT_LINE'), [
                '{days}' => (int) Arr::get($stat, 'absent_count'),
            ]);
        }

        return $text;
    }

    /**
     * 補打卡與加班（不算「狀況」，有就報）
     *
     * @param array $report
     * @param array $stat
     * @return string
     */
    private function personalExtras(array $report, array $stat)
    {
        $text = '';

        if ((int) Arr::get($stat, 'amend_count', 0) > 0) {
            $text .= strtr((string) Arr::get($report, 'AMEND_LINE'), [
                '{count}' => (int) Arr::get($stat, 'amend_count'),
            ]);
        }

        if ((int) Arr::get($stat, 'overtime_total_minutes', 0) > 0) {
            $text .= strtr((string) Arr::get($report, 'OVERTIME_LINE'), [
                '{hours}' => $this->hours($report, (int) Arr::get($stat, 'overtime_total_minutes')),
            ]);
        }

        return $text;
    }

    // ---------------------------------------------------------------
    //  小工具
    // ---------------------------------------------------------------

    /**
     * @param string $type
     * @return string
     */
    private function title($type)
    {
        return (string) config(
            $type === self::TYPE_MONTHLY
                ? 'constants.ATTENDANCE_REPORT.MONTHLY_TITLE'
                : 'constants.ATTENDANCE_REPORT.WEEKLY_TITLE'
        );
    }

    /**
     * 勉勵話裡「這一期」的說法
     *
     * @param string $type
     * @return string
     */
    private function periodWord($type)
    {
        return (string) config(
            $type === self::TYPE_MONTHLY
                ? 'constants.ATTENDANCE_REPORT.PERIOD_MONTHLY'
                : 'constants.ATTENDANCE_REPORT.PERIOD_WEEKLY'
        );
    }

    /**
     * 「2026-10-06 ～ 2026-10-12」
     *
     * @param array $report
     * @param array $range
     * @return string
     */
    private function rangeText(array $report, array $range)
    {
        return strtr((string) Arr::get($report, 'RANGE'), [
            '{start}' => (string) Arr::get($range, 'start'),
            '{end}'   => (string) Arr::get($range, 'end'),
        ]);
    }

    /**
     * 單筆請假的區間字串。起訖同一天時只寫一次，不要寫成「10-08 ～ 10-08」
     *
     * @param array $report
     * @param array $leaveRange
     * @return string
     */
    private function leaveRangeText(array $report, array $leaveRange)
    {
        $start = (string) Arr::get($leaveRange, 'start');
        $end = (string) Arr::get($leaveRange, 'end');

        return $start === $end ? $start : strtr((string) Arr::get($report, 'RANGE'), [
            '{start}' => $start,
            '{end}'   => $end,
        ]);
    }

    /**
     * 請假的「N 天」「N 小時」「N 天 N 小時」；都沒有回空字串
     *
     * ⚠ 天與小時**不互相換算**：一天幾小時取決於班別，硬換會得到一個
     * 誰都不認得的數字。
     *
     * @param array $report
     * @param array $stat
     * @return string
     */
    private function leaveAmount(array $report, array $stat)
    {
        $days = (int) Arr::get($stat, 'leave_days', 0);
        $hours = (float) Arr::get($stat, 'leave_hours', 0);

        if ($days > 0 && $hours > 0) {
            return strtr((string) Arr::get($report, 'UNIT_LEAVE_BOTH'), [
                '{days}'  => $days,
                '{hours}' => $this->trimFloat($hours),
            ]);
        }

        if ($days > 0) {
            return strtr((string) Arr::get($report, 'UNIT_LEAVE_DAYS'), ['{days}' => $days]);
        }

        if ($hours > 0) {
            return strtr((string) Arr::get($report, 'UNIT_HOURS'), ['{hours}' => $this->trimFloat($hours)]);
        }

        return '';
    }

    /**
     * 分鐘數的顯示
     *
     * @param array $report
     * @param int   $minutes
     * @return string
     */
    private function minutes(array $report, $minutes)
    {
        return strtr((string) Arr::get($report, 'UNIT_MINUTES'), ['{minutes}' => (int) $minutes]);
    }

    /**
     * 分鐘換成小時的顯示（加班用）
     *
     * @param array $report
     * @param int   $minutes
     * @return string
     */
    private function hours(array $report, $minutes)
    {
        return strtr((string) Arr::get($report, 'UNIT_HOURS'), [
            '{hours}' => $this->trimFloat($minutes / 60),
        ]);
    }

    /**
     * 3.0 → 「3」、3.5 → 「3.5」
     *
     * @param float $value
     * @return string
     */
    private function trimFloat($value)
    {
        return rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
    }

    /**
     * user_id => 暱稱
     *
     * ⚠ 一次撈完，不要在迴圈裡逐人查 —— 那就是 N+1。
     *
     * @param array $ids
     * @return array
     */
    private function nicknamesOf(array $ids)
    {
        $names = [];

        foreach ($this->userRepository->getNamesByIds(array_unique($ids)) as $user) {
            $names[(int) $user->id] = $user->nickname;
        }

        return $names;
    }
}

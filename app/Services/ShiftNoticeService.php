<?php

namespace App\Services;

use App\Repositories\ShiftAssignmentRepository;
use App\Repositories\ShiftRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 每天早上私訊今日班表
 *
 * 兩種收件人，內容不一樣（需求方 2026-10-06）：
 *
 * | 收件人 | 內容 |
 * |---|---|
 * | 設定頁指定的人（可多位） | 今天**每個班次**各是誰，沒人排的班特別標出來 |
 * | 今天有班的每個人 | 只有自己那一筆，不列同班的其他人 |
 *
 * ⚠ **Telegram 不讓 bot 主動私訊沒對話過的人**（403）。綁定與送出都交給
 * `StaffDmService`，這裡只負責組內容與決定發給誰。
 *
 * ⚠ **一個人失敗不能讓整輪停掉**：逐人送、逐人判斷結果，最後把沒收到的
 * 彙總成一則發到內部支援群組（比照 `StationCreditAlertService::run()`）。
 */
class ShiftNoticeService
{
    /*
     * 這支發到內部群組的訊息屬於哪一類通知。
     *
     * 決定它進哪個 Telegram 話題 —— 對應 `constants.SUPPORT_TOPIC.TYPES` 的 key，
     * 由設定頁的話題清單勾選。沒被任何話題勾到就發到群組主區。
     */
    const NOTICE_TYPE = 'shift_notice';

    /** @var string 跳過原因：沒有設定任何收件人 */
    const SKIP_NO_MANAGER = 'no_manager';

    /** @var string 跳過原因：收件人全都沒私訊過 bot */
    const SKIP_NOT_BOUND = 'not_bound';

    /** @var string 跳過原因：Telegram 拒收（多半是被封鎖或其實沒綁定） */
    const SKIP_SEND_FAILED = 'send_failed';

    private $assignmentRepository;
    private $shiftRepository;
    private $userRepository;
    private $staffDm;
    private $supportGroup;
    private $appSettingService;

    public function __construct(
        ShiftAssignmentRepository $assignmentRepository,
        ShiftRepository $shiftRepository,
        UserRepository $userRepository,
        StaffDmService $staffDm,
        SupportGroupService $supportGroup,
        AppSettingService $appSettingService
    ) {
        $this->assignmentRepository = $assignmentRepository;
        $this->shiftRepository = $shiftRepository;
        $this->userRepository = $userRepository;
        // 私訊的綁定檢查與失敗處理都在這支，班表與提醒統計共用
        $this->staffDm = $staffDm;
        // 沒收到的人彙總回報到這裡
        $this->supportGroup = $supportGroup;
        // 主管收件人存在 app_setting，換人不用動程式
        $this->appSettingService = $appSettingService;
    }

    /**
     * 跑一輪：主管一則 + 今天有班的每個人各一則
     *
     * @param string|null $date   哪一天（預設今天）
     * @param bool        $dryRun 只組訊息不發送（測試按鈕用）
     * @return array 結果，給 Command 與後台顯示
     */
    public function run($date = null, $dryRun = false)
    {
        $date = filled($date) ? $date : now()->toDateString();

        $assignments = $this->assignmentRepository->getByDateRange($date, $date);
        $shifts = $this->shiftRepository->allActive();

        $managerText = $this->buildManagerText($date, $assignments, $shifts);
        $manager = $this->sendToManagers($managerText, $dryRun);
        $personal = $this->sendToEveryone($assignments, $shifts, $dryRun);

        $failed = array_merge(
            Arr::get($manager, 'failed', []),
            Arr::get($personal, 'failed', [])
        );

        if (!$dryRun && filled($failed)) {
            $this->reportFailures($failed);
        }

        return [
            'date'          => $date,
            'manager_text'  => $managerText,
            'manager_sent'  => Arr::get($manager, 'sent', 0),
            'manager_skip'  => Arr::get($manager, 'reason'),
            'manager_names' => Arr::get($manager, 'names', []),
            'personal_sent' => Arr::get($personal, 'sent', 0),
            'personal_text' => Arr::get($personal, 'sample'),
            'failed'        => $failed,
        ];
    }

    /**
     * 設定頁要的資料
     *
     * 候選人帶 `dm_ready` —— 設定頁要能當場看出「選了這個人也發不出去」，
     * 不然要等到隔天早上八點沒收到才知道。
     *
     * @return array
     */
    public function forPage()
    {
        $candidates = [];

        foreach ($this->userRepository->getDmCandidates() as $user) {
            $candidates[] = [
                'id'       => (int) $user->id,
                'nickname' => (string) $user->nickname,
                'dm_ready' => $this->staffDm->canDm($user),
            ];
        }

        return [
            'manager_user_ids' => $this->appSettingService->getIntList(AppSettingService::KEY_SHIFT_NOTICE_MANAGER),
            'candidates'      => $candidates,
            'send_at'         => (string) config('constants.SHIFT_NOTICE.SEND_AT'),
        ];
    }

    /**
     * 存設定頁
     *
     * @param array $params
     * @param int   $operatorId
     * @return void
     */
    public function updateSetting(array $params, $operatorId)
    {
        $this->appSettingService->put(
            AppSettingService::KEY_SHIFT_NOTICE_MANAGER,
            // 全部取消勾選時存 null —— 轉換與清空的規則都在 idListValue() 裡
            $this->appSettingService->idListValue(Arr::get($params, 'manager_user_ids', [])),
            $operatorId
        );
    }

    /**
     * 測試發送：真的把今天的班表私訊給設定的主管
     *
     * ⚠ 刻意**不是** dry-run —— 測試按鈕要回答的是「訊息到得了他手機嗎」，
     * 只組字串不發送的話，沒綁定、被封鎖這些真正會出事的狀況全都測不到。
     * 只發主管那一則，不會去打擾今天有班的同仁。
     *
     * @return array sent / reason
     */
    public function test()
    {
        $date = now()->toDateString();
        $assignments = $this->assignmentRepository->getByDateRange($date, $date);
        $shifts = $this->shiftRepository->allActive();

        $text = (string) config('constants.SHIFT_NOTICE.TEST_PREFIX')
            . $this->buildManagerText($date, $assignments, $shifts);

        $result = $this->sendToManagers($text, false);

        return [
            'sent'   => Arr::get($result, 'sent', 0),
            'reason' => Arr::get($result, 'reason'),
            'names'  => Arr::get($result, 'names', []),
            'failed' => Arr::get($result, 'failed', []),
        ];
    }

    /**
     * 組主管那份：每個班次各是誰
     *
     * ⚠ **走的是「所有啟用中的班別」而不是「今天有排班的班別」** ——
     * 需求方要的是「哪個班沒人」，那種班在 `$assignments` 裡根本不存在，
     * 只看排班資料永遠列不出來。
     *
     * @param string     $date
     * @param iterable   $assignments 今天的排班
     * @param iterable   $shifts      啟用中的班別
     * @return string
     */
    private function buildManagerText($date, $assignments, $shifts)
    {
        $text = strtr((string) config('constants.SHIFT_NOTICE.MANAGER_HEADER'), [
            '{date}' => $this->formatDate($date),
        ]);

        if (blank($shifts)) {
            return $text . (string) config('constants.SHIFT_NOTICE.MANAGER_NO_SHIFT');
        }

        // 先把今天的排班依班別分組，下面逐班取用
        $byShift = [];

        foreach ($assignments as $assignment) {
            $byShift[(int) $assignment->shift_id][] = $assignment->user_id;
        }

        $names = $this->namesOf($assignments);
        $hasAny = false;

        foreach ($shifts as $shift) {
            $userIds = (array) Arr::get($byShift, (int) $shift->id, []);
            $hasAny = $hasAny || filled($userIds);

            $members = filled($userIds)
                ? implode('、', $this->pickNames($names, $userIds))
                : (string) config('constants.SHIFT_NOTICE.MANAGER_EMPTY_SHIFT');

            $text .= strtr((string) config('constants.SHIFT_NOTICE.MANAGER_SHIFT'), [
                '{shift}'      => (string) $this->shiftLabel($shift),
                '{work_time}'  => $this->timeRange($shift->start_time, $shift->end_time),
                '{reply_time}' => $this->timeRange($shift->reply_start_time, $shift->reply_end_time),
                '{members}'    => $members,
            ]);
        }

        if (!$hasAny) {
            $text .= (string) config('constants.SHIFT_NOTICE.MANAGER_NO_SHIFT');
        }

        return $text;
    }

    /**
     * 發完整班表給設定的收件人（可以是多位）
     *
     * ⚠ 收件人**不是**「今天有班的人」，是設定頁勾選的那幾位 ——
     * 他們拿到的是全部班次，有班的人另外拿自己那一份。
     *
     * @param string $text
     * @param bool   $dryRun
     * @return array sent（送出幾則）/ reason / failed / names
     */
    private function sendToManagers($text, $dryRun)
    {
        $managerIds = $this->appSettingService->getIntList(AppSettingService::KEY_SHIFT_NOTICE_MANAGER);

        if (blank($managerIds)) {
            return ['sent' => 0, 'reason' => self::SKIP_NO_MANAGER, 'failed' => [], 'names' => []];
        }

        /*
         * 空跑只回「幾個人會收到」，不去判斷綁定狀態 ——
         * `--dry-run` 要看的是內容與排版，不是誰收得到（那是測試發送的事）。
         */
        if ($dryRun) {
            return ['sent' => count($managerIds), 'reason' => null, 'failed' => [], 'names' => []];
        }

        return $this->staffDm->sendToUserIds($managerIds, $text);
    }

    /**
     * 發給今天每個有班的人
     *
     * @param iterable $assignments
     * @param iterable $shifts
     * @param bool     $dryRun
     * @return array sent / sample / failed
     */
    private function sendToEveryone($assignments, $shifts, $dryRun)
    {
        $shiftMap = [];

        foreach ($shifts as $shift) {
            $shiftMap[(int) $shift->id] = $shift;
        }

        $users = $this->userRepository->getForDmByIds($this->userIdsOf($assignments));
        $userMap = [];

        foreach ($users as $user) {
            $userMap[(int) $user->id] = $user;
        }

        $sent = 0;
        $failed = [];
        $sample = null;

        foreach ($assignments as $assignment) {
            $user = Arr::get($userMap, (int) $assignment->user_id);
            $shift = Arr::get($shiftMap, (int) $assignment->shift_id);

            // 班別被停用了還留著排班 —— 不是這支該處理的，跳過但記一筆
            if (blank($shift)) {
                Log::warning('今日班表：排班的班別已停用，略過', [
                    'assignment_id' => $assignment->id,
                    'shift_id'      => $assignment->shift_id,
                ]);

                continue;
            }

            $text = $this->buildPersonalText($user, $shift);
            $sample = filled($sample) ? $sample : $text;

            if (blank($user) || !$this->staffDm->canDm($user)) {
                $failed[] = filled($user) ? $user->nickname : "#{$assignment->user_id}";

                continue;
            }

            if ($dryRun) {
                $sent++;

                continue;
            }

            if ($this->staffDm->send($user, $text)) {
                $sent++;

                continue;
            }

            $failed[] = $user->nickname;
        }

        return ['sent' => $sent, 'sample' => $sample, 'failed' => $failed];
    }

    /**
     * 組個人那份
     *
     * ⚠ **不列同班的其他人**（需求方 2026-10-06 指定）—— 那等於把別人的
     * 班別告訴他。
     *
     * @param object|null $user
     * @param object      $shift
     * @return string
     */
    private function buildPersonalText($user, $shift)
    {
        return strtr((string) config('constants.SHIFT_NOTICE.PERSONAL'), [
            '{name}'       => filled($user) ? (string) $user->nickname : '',
            '{shift}'      => (string) $this->shiftLabel($shift),
            '{work_time}'  => $this->timeRange($shift->start_time, $shift->end_time),
            '{reply_time}' => $this->timeRange($shift->reply_start_time, $shift->reply_end_time),
        ]);
    }

    /**
     * 把沒收到的人彙總回報到內部群組
     *
     * ⚠ **彙總成一則**，不逐人發 —— 十個人沒綁定就會洗十則版。
     *
     * @param array $names
     * @return void
     */
    private function reportFailures(array $names)
    {
        if (!$this->supportGroup->isConfigured()) {
            return;
        }

        try {
            $this->supportGroup->send(strtr((string) config('constants.SHIFT_NOTICE.FAILED_SUMMARY'), [
                '{count}' => count($names),
                '{names}' => implode('、', $names),
            ]), null, null, self::NOTICE_TYPE);
        } catch (\Exception $e) {
            Log::error('今日班表的未送達彙總發不出去', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 班別顯示名稱（沒填 display_name 就用 name）
     *
     * @param object $shift
     * @return string
     */
    private function shiftLabel($shift)
    {
        return filled($shift->display_name) ? $shift->display_name : $shift->name;
    }

    /**
     * 把兩個時間接成「09:00-18:00」
     *
     * 任一邊沒設定就整段顯示「未設定」—— 顯示「09:00-」看了只會更困惑。
     *
     * @param string|null $start
     * @param string|null $end
     * @return string
     */
    private function timeRange($start, $end)
    {
        if (blank($start) || blank($end)) {
            return (string) config('constants.SHIFT_NOTICE.TIME_UNSET');
        }

        return $this->shortTime($start) . '-' . $this->shortTime($end);
    }

    /**
     * `09:00:00` → `09:00`
     *
     * @param string $time
     * @return string
     */
    private function shortTime($time)
    {
        return mb_substr((string) $time, 0, 5);
    }

    /**
     * `2026-10-07` → `10/07（週二）`
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

    /**
     * 取這批排班涉及的所有 user_id
     *
     * @param iterable $assignments
     * @return array
     */
    private function userIdsOf($assignments)
    {
        $ids = [];

        foreach ($assignments as $assignment) {
            $ids[] = (int) $assignment->user_id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * user_id => 暱稱
     *
     * ⚠ 不走 `$assignment->user` 關聯 —— 那個關聯的 select 不在這支手上，
     * 哪天被改掉就會變成一片空白而且不會報錯（這個專案踩過好幾次）。
     *
     * @param iterable $assignments
     * @return array
     */
    private function namesOf($assignments)
    {
        $names = [];

        foreach ($this->userRepository->getNamesByIds($this->userIdsOf($assignments)) as $user) {
            $names[(int) $user->id] = $user->nickname;
        }

        return $names;
    }

    /**
     * 依 id 取出名字，查不到的用 `#id` 佔位
     *
     * 佔位而不是略過：主管看到「#12」至少知道有人排了班卻查不到資料，
     * 整個不顯示的話那個班看起來就像少一個人。
     *
     * @param array $names
     * @param array $userIds
     * @return array
     */
    private function pickNames(array $names, array $userIds)
    {
        $picked = [];

        foreach ($userIds as $id) {
            $picked[] = (string) Arr::get($names, (int) $id, "#{$id}");
        }

        return $picked;
    }
}

<?php

namespace App\Services;

use App\Models\ConversationFollowup;
use App\Repositories\ConversationFollowupRemindRepository;
use App\Repositories\ConversationFollowupRepository;
use App\Services\Notify\NoticeText;
use Illuminate\Support\Arr;

/**
 * 待追蹤事項的每日統計
 *
 * 需求方 2026-10-11 選了「獨立一則」而不是併進超時統計那則 ——
 * 這則講的是「誰在拖、我們追了沒」，收件人跟超時統計不一定重疊。
 *
 * ⚠ **沒事那天也要發**（比照 [[remind-escalation]]）：安靜不動時分不出
 * 「昨天沒事」還是「排程壞了」。
 *
 * ⚠ **只發完整版，不發個人版。** 待追蹤事項的決定權在當班人員，而「誰今天
 * 當班」每天不同 —— 逐人發「你昨天被問了幾次」沒有任何人需要據此行動。
 */
class FollowupReportService
{
    private $repository;
    private $remindRepository;
    private $staffDm;
    private $appSettingService;
    private $noticeText;

    public function __construct(
        ConversationFollowupRepository $repository,
        ConversationFollowupRemindRepository $remindRepository,
        StaffDmService $staffDm,
        AppSettingService $appSettingService,
        NoticeText $noticeText
    ) {
        $this->repository = $repository;
        $this->remindRepository = $remindRepository;
        // 綁定檢查與送出都在這支，跟班表／超時統計共用
        $this->staffDm = $staffDm;
        $this->appSettingService = $appSettingService;
        $this->noticeText = $noticeText;
    }

    /**
     * 跑一輪
     *
     * @param string|null $date   統計哪一天（預設前一天）
     * @param bool        $dryRun 只組內容不發送
     * @return array date / text / sent / reason / names / failed
     */
    public function run($date = null, $dryRun = false)
    {
        $date = filled($date) ? $date : now()->subDay()->toDateString();
        $text = $this->buildText($date);
        $result = $this->send($text, $dryRun);

        return [
            'date'   => $date,
            'text'   => $text,
            'sent'   => Arr::get($result, 'sent', 0),
            'reason' => Arr::get($result, 'reason'),
            'names'  => Arr::get($result, 'names', []),
            'failed' => Arr::get($result, 'failed', []),
        ];
    }

    /**
     * 測試發送：真的把昨天那份發給勾選的人
     *
     * 刻意不是 dry-run，理由同 `RemindReportService::test()`：沒綁定、
     * 被封鎖這些真正會出事的狀況，只組字串測不到。
     *
     * @return array sent / reason / names / failed
     */
    public function test()
    {
        $text = (string) config('constants.FOLLOWUP.REPORT.TEST_PREFIX')
            . $this->buildText(now()->subDay()->toDateString());

        return $this->send($text, false);
    }

    /**
     * 組那一則訊息
     *
     * @param string $date Y-m-d
     * @return string
     */
    public function buildText($date)
    {
        $report = (array) config('constants.FOLLOWUP.REPORT');
        $stat = $this->collect($date);

        $text = strtr((string) Arr::get($report, 'HEADER'), [
            '{date}' => $this->noticeText->date($date),
        ]);

        // 昨天沒動、現在也沒有掛著的 —— 一句話收掉，不要印三行 0
        if ((int) Arr::get($stat, 'opened') < 1 && (int) Arr::get($stat, 'open') < 1) {
            return $text . (string) Arr::get($report, 'EMPTY');
        }

        $text .= strtr((string) Arr::get($report, 'SUMMARY'), [
            '{opened}'   => (int) Arr::get($stat, 'opened'),
            '{customer}' => (int) Arr::get($stat, 'customer'),
            '{staff}'    => (int) Arr::get($stat, 'staff'),
            '{closed}'   => (int) Arr::get($stat, 'closed'),
            '{open}'     => (int) Arr::get($stat, 'open'),
        ]);

        if ((int) Arr::get($stat, 'no_mention') > 0) {
            $text .= strtr((string) Arr::get($report, 'NO_MENTION'), [
                '{times}' => (int) Arr::get($stat, 'no_mention'),
            ]);
        }

        $text .= $this->buildSection($report, 'OLDEST_TITLE', Arr::get($stat, 'oldest'));
        $text .= $this->buildSection($report, 'STALLED_TITLE', Arr::get($stat, 'stalled'));

        return $text;
    }

    /**
     * 把數字撈出來（後台若要做頁面也走這一支）
     *
     * @param string $date Y-m-d
     * @return array
     */
    public function collect($date)
    {
        $sources = (array) config('constants.FOLLOWUP.SOURCE');
        $limit = (int) config('constants.FOLLOWUP.REPORT.MAX_LINES');

        $new = $this->repository->countNewByRange($date, $date);
        $closed = $this->repository->countClosedByRange($date, $date);

        $bySource = [];

        foreach ($new as $row) {
            $bySource[(int) $row->source] = (int) $row->total;
        }

        return [
            'opened'   => array_sum($bySource),
            'customer' => (int) Arr::get($bySource, (int) Arr::get($sources, 'CUSTOMER'), 0),
            'staff'    => (int) Arr::get($bySource, (int) Arr::get($sources, 'STAFF'), 0),
            'closed'   => (int) $closed->sum('total'),
            'open'     => $this->repository->countOpen(),
            'no_mention' => $this->remindRepository->countNoMention($date, $date),
            'oldest'   => $this->repository->getOpenTop('wait_at', $limit),
            /*
             * 拖最多次的那幾筆。
             *
             * ⚠ 這是**唯一能看出「習慣性拖延」的訊號** —— 單看「等最久」
             * 只會一直是同一筆最舊的，看不出哪個客人每次都說稍等。
             */
            'stalled'  => $this->repository->getOpenTop('wait_count', $limit),
        ];
    }

    /**
     * 「等最久／拖最多次」那一段
     *
     * @param array $report
     * @param string $titleKey
     * @param \Illuminate\Support\Collection|null $items
     * @return string
     */
    private function buildSection(array $report, $titleKey, $items)
    {
        if (blank($items)) {
            return '';
        }

        $text = (string) Arr::get($report, $titleKey);
        $max = (int) Arr::get($report, 'MAX_LINES');
        $shown = 0;

        foreach ($items as $item) {
            if ($shown >= $max) {
                break;
            }

            $text .= $this->buildLine($report, $item);
            $shown++;
        }

        /*
         * ⚠ 截掉的要報出來（`MORE`）。只印前五筆而不說還有幾筆，
         * 讀的人會以為那就是全部 —— 沉默的截斷等於報錯的數字。
         */
        if ($items->count() > $shown) {
            $text .= strtr((string) Arr::get($report, 'MORE'), [
                '{count}' => $items->count() - $shown,
            ]);
        }

        return $text;
    }

    /**
     * 一筆的那三行
     *
     * @param array                $report
     * @param ConversationFollowup $item
     * @return string
     */
    private function buildLine(array $report, ConversationFollowup $item)
    {
        return strtr((string) Arr::get($report, 'LINE'), [
            '{icon}'   => (string) config("constants.FOLLOWUP.STATUS_ICON.{$item->status}"),
            '{source}' => (string) config("constants.FOLLOWUP.SOURCE_LABEL.{$item->source}"),
            '{group}'  => filled($item->group) ? (string) $item->group->title : "#{$item->telegram_group_id}",
            '{text}'   => $this->noticeText->shorten($item->wait_text, (int) Arr::get($report, 'TEXT_CHARS')),
            '{waits}'  => (int) $item->wait_count,
            '{rounds}' => (int) $item->ask_round,
            '{waited}' => $this->noticeText->waited($item->wait_at, (array) config('constants.FOLLOWUP')),
        ]);
    }

    /**
     * 私訊給設定的收件人
     *
     * @param string $text
     * @param bool   $dryRun
     * @return array
     */
    private function send($text, $dryRun)
    {
        $userIds = $this->appSettingService->getIntList(AppSettingService::KEY_FOLLOWUP_REPORT_MANAGER);

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
}

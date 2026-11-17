<?php

namespace App\Services;

use App\Repositories\AutoReplyTicketRepository;
use App\Services\Notify\NoticeText;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

/**
 * 求助單每日去向統計
 *
 * ⚠⚠ **這則不是「單子去哪了」的流水帳，是「題庫有沒有在長」的報表。**
 *
 * > 需求方 2026-11-18：「有些客服人員，會都只回覆客人，而不去維護新增題庫，
 * > 造成後續 AI 要回覆時沒有依據，違背當初我開發的題庫＋AI 功能。」
 *
 * 會進這張表的單，全都是 **AI 答不出來**而轉人工的 —— 也就是最該被收進題庫的
 * 那一批。所以每個數字都要能回答「這張單有沒有讓下一次的 AI 變聰明」，
 * 排版順序（維護率 → 有讓題庫長大 → 回了客人但題庫沒長）就是這個動機本身，
 * 改文案時不要把順序打散。
 *
 * ⚠ **分類完全靠 `close_action`，不要拿 `status` 來推**：
 * ②「回覆並加入題庫」會把 `REPLIED` 蓋成 `SAVED`、①「直接回覆」與
 * 「用既有題目回覆」的 `status` 一模一樣 —— 理由見
 * `constants.AUTO_REPLY.CLOSE_ACTION`。
 *
 * ⚠ **發到內部支援群組**（需求方指定勾在「AI 轉來的問題」話題），不是私訊。
 * 這張報表講的是那一區的事，放在一起才看得到脈絡。
 */
class TicketReportService
{
    /** @var string 通知類型（`constants.SUPPORT_TOPIC.TYPES` 的 key） */
    const NOTICE_TYPE = 'ticket_daily_report';

    /** @var string 沒設定內部支援群組 */
    const SKIP_NO_GROUP = 'no_group';

    /** @var string 送出失敗 */
    const SKIP_SEND_FAILED = 'send_failed';

    private $ticketRepository;
    private $supportGroup;
    private $noticeText;

    public function __construct(
        AutoReplyTicketRepository $ticketRepository,
        SupportGroupService $supportGroup,
        NoticeText $noticeText
    ) {
        $this->ticketRepository = $ticketRepository;
        $this->supportGroup = $supportGroup;
        // 日期排版跟班表／待接手／超時統計共用，不各寫一份
        $this->noticeText = $noticeText;
    }

    /**
     * 跑一輪
     *
     * @param string|null $date   統計哪一天（預設前一天）
     * @param bool        $dryRun 只組內容不發送
     * @return array date / text / stat / sent / reason
     */
    public function run($date = null, $dryRun = false)
    {
        $date = filled($date) ? $date : now()->subDay()->toDateString();

        /*
         * ⚠ **短路擺在統計之前**：正式跑而且沒設內部群組時，這份報表哪裡都
         * 去不了 —— 先讀設定（app_setting，有快取）再決定要不要查那幾次
         * GROUP BY，不要反過來。
         *
         * 空跑不走這一關：那是用來看內容與排版的，沒設群組也該印得出來。
         */
        if (!$dryRun && !$this->supportGroup->isConfigured()) {
            Log::warning('求助單每日統計沒發出去：未設定內部支援群組');

            return [
                'date'   => $date,
                'text'   => null,
                'stat'   => [],
                'sent'   => false,
                'reason' => self::SKIP_NO_GROUP,
            ];
        }

        $stat = $this->collect($date);

        $result = [
            'date' => $date,
            'text' => $this->buildText($date, $stat),
            'stat' => $stat,
            'sent' => false,
            'reason' => null,
        ];

        if ($dryRun) {
            return $result;
        }

        if ($this->send(Arr::get($result, 'text'))) {
            return array_merge($result, ['sent' => true]);
        }

        return array_merge($result, ['reason' => self::SKIP_SEND_FAILED]);
    }

    /**
     * 測試發送：真的把昨天那份發到群組
     *
     * 刻意不是 dry-run，理由同其他幾支報表：沒設群組、話題被刪掉這些真正會
     * 出事的狀況，只組字串測不到。
     *
     * @return array sent / reason
     */
    public function test()
    {
        // 短路同 `run()`：沒設群組就不必查統計了
        if (!$this->supportGroup->isConfigured()) {
            return ['sent' => false, 'reason' => self::SKIP_NO_GROUP];
        }

        $date = now()->subDay()->toDateString();
        $text = (string) config('constants.AUTO_REPLY.DAILY_REPORT.TEST_PREFIX')
            . $this->buildText($date, $this->collect($date));

        return $this->send($text)
            ? ['sent' => true, 'reason' => null]
            : ['sent' => false, 'reason' => self::SKIP_SEND_FAILED];
    }

    /**
     * 把數字撈出來（後台若要做頁面也走這一支）
     *
     * @param string $date Y-m-d
     * @return array{opened:int, closed:int, grew:int, rate:int, unclosed:int, open:int,
     *               actions:array<int,int>, unknown:int, by_who:array<int,array{name:string,count:int}>}
     */
    public function collect($date)
    {
        $actions = [];

        foreach ($this->ticketRepository->countByActionInRange($date, $date) as $row) {
            $actions[(int) $row->close_action] = (int) $row->total;
        }

        $unclosed = (int) $this->ticketRepository->countUnclosedInRange($date, $date);
        $closed = array_sum($actions);
        $grew = $this->sumOf($actions, (array) config('constants.AUTO_REPLY.CLOSE_ACTION_GREW'));

        return [
            'opened'   => $closed + $unclosed,
            'closed'   => $closed,
            'grew'     => $grew,
            // 分母是「已結案的」—— 還沒處理完的那幾張還有機會進題庫
            'rate'     => $closed > 0 ? (int) round($grew * 100 / $closed) : 0,
            'unclosed' => $unclosed,
            'open'     => (int) $this->ticketRepository->countOpen(),
            'actions'  => $actions,
            'unknown'  => $this->unknownCount($actions),
            'by_who'   => $this->replyOnlyByWho($date),
        ];
    }

    /**
     * 組那一則訊息
     *
     * @param string $date Y-m-d
     * @param array  $stat `collect()` 的結果
     * @return string
     */
    public function buildText($date, array $stat)
    {
        $report = (array) config('constants.AUTO_REPLY.DAILY_REPORT');

        $text = strtr((string) Arr::get($report, 'HEADER'), [
            '{date}' => $this->noticeText->date($date),
        ]);

        // 那天一張都沒轉人工 —— 一句話收掉，不要印一整排 0
        if ((int) Arr::get($stat, 'opened') < 1) {
            return $text
                . (string) Arr::get($report, 'EMPTY')
                . $this->openLine($report, $stat);
        }

        $text .= $this->summary($report, $stat);
        $text .= $this->section($report, $stat, 'GREW_TITLE', config('constants.AUTO_REPLY.CLOSE_ACTION_GREW'));
        $text .= $this->section($report, $stat, 'NO_GREW_TITLE', $this->noGrewButReplied());
        $text .= $this->section($report, $stat, 'NONE_TITLE', $this->noReplyActions());
        $text .= $this->unknownLine($report, $stat);

        $text .= strtr((string) Arr::get($report, 'PENDING'), [
            '{count}' => (int) Arr::get($stat, 'unclosed'),
        ]);

        return $text . $this->openLine($report, $stat);
    }

    /**
     * 開頭那一句：開了幾張、維護率多少
     *
     * ⚠ 一張都還沒結案時**不要印 0%** —— 那會被看成「昨天一個題庫都沒建」，
     * 但實際上是還沒人處理完，兩件事差很多。
     *
     * @param array $report
     * @param array $stat
     * @return string
     */
    private function summary(array $report, array $stat)
    {
        if ((int) Arr::get($stat, 'closed') < 1) {
            return strtr((string) Arr::get($report, 'SUMMARY_NO_CLOSED'), [
                '{opened}' => (int) Arr::get($stat, 'opened'),
            ]);
        }

        return strtr((string) Arr::get($report, 'SUMMARY'), [
            '{opened}' => (int) Arr::get($stat, 'opened'),
            '{rate}'   => (int) Arr::get($stat, 'rate'),
            '{grew}'   => (int) Arr::get($stat, 'grew'),
            '{closed}' => (int) Arr::get($stat, 'closed'),
        ]);
    }

    /**
     * 一個段落（標題 ＋ 底下那幾行）
     *
     * ⚠ **整段都是 0 就整段不印**。三段固定印的話，沒事的日子會是一整片 0，
     * 真正有東西的那一段反而不顯眼 —— 這則是早上掃一眼的東西。
     * （段落**裡面**的 0 照印，固定版面才好跟前一天比對。）
     *
     * @param array $report
     * @param array $stat
     * @param string $titleKey
     * @param array $actions 這一段包含哪些 close_action
     * @return string
     */
    private function section(array $report, array $stat, $titleKey, array $actions)
    {
        $counts = (array) Arr::get($stat, 'actions');
        $total = $this->sumOf($counts, $actions);

        if ($total < 1) {
            return '';
        }

        $text = strtr((string) Arr::get($report, $titleKey), ['{total}' => $total]);
        $labels = (array) Arr::get($report, 'LABEL');

        foreach ($actions as $action) {
            $text .= strtr((string) Arr::get($report, 'LINE'), [
                '{label}' => (string) Arr::get($labels, (int) $action),
                '{count}' => (int) Arr::get($counts, (int) $action, 0),
            ]);

            $text .= $this->byWhoLine($report, $stat, (int) $action);
        }

        return $text;
    }

    /**
     * 「直接打字回覆」底下那行名字
     *
     * ⚠ **只有這一種要點名。** 需求方的動機是找出「只回客人、不建題庫」的人，
     * 其他幾種要嘛題庫有長、要嘛本來就沒有人經手。全部都印名字會變成
     * 每天一張點名表，那不是這則通知的用途。
     *
     * @param array $report
     * @param array $stat
     * @param int   $action
     * @return string
     */
    private function byWhoLine(array $report, array $stat, $action)
    {
        if ($action !== (int) config('constants.AUTO_REPLY.CLOSE_ACTION.REPLY_ONLY')) {
            return '';
        }

        $names = (array) Arr::get($stat, 'by_who');

        if (blank($names)) {
            return '';
        }

        $parts = [];

        foreach ($names as $row) {
            $parts[] = strtr((string) Arr::get($report, 'BY_WHO_ONE'), [
                '{name}'  => (string) Arr::get($row, 'name'),
                '{count}' => (int) Arr::get($row, 'count'),
            ]);
        }

        return strtr((string) Arr::get($report, 'BY_WHO'), [
            '{names}' => implode('、', $parts),
        ]);
    }

    /**
     * 「直接打字回覆」那些單是誰經手的
     *
     * ⚠ `answered_by` 存的是 Telegram 顯示名稱，拿不到名字時用「未具名」——
     * 整列丟掉的話這一行的人數會跟上面那行的總數對不起來，看的人會以為算錯。
     *
     * @param string $date
     * @return array<int, array{name:string, count:int}>
     */
    private function replyOnlyByWho($date)
    {
        $rows = $this->ticketRepository->countByAnsweredBy(
            $date,
            $date,
            config('constants.AUTO_REPLY.CLOSE_ACTION.REPLY_ONLY')
        );

        $names = [];

        foreach ($rows as $row) {
            $names[] = [
                'name'  => filled($row->answered_by) ? (string) $row->answered_by : '未具名',
                'count' => (int) $row->total,
            ];
        }

        return $names;
    }

    /**
     * 「回了客人，但題庫沒長」那一段包含哪些動作
     *
     * ＝ 有回覆客人的 − 有讓題庫長大的。寫成減法而不是再列一份清單，
     * 是為了讓三份常數不會各改各的 —— 新增一種處理方式時只要歸好類，
     * 這裡自己會對。
     *
     * @return array
     */
    private function noGrewButReplied()
    {
        return array_values(array_diff(
            (array) config('constants.AUTO_REPLY.CLOSE_ACTION_REPLIED'),
            (array) config('constants.AUTO_REPLY.CLOSE_ACTION_GREW')
        ));
    }

    /**
     * 「沒有回覆」那一段包含哪些動作
     *
     * ＝ 全部 − 有回覆的 − 有讓題庫長大的（兩邊都扣，理由同上）。
     *
     * ⚠ 逾時被收掉的（`EXPIRED`）也在這一段 —— 那確實是「客人沒有得到回應」，
     * 而且跟「同仁按忽略」擺在一起才看得出那天到底有多少人沒被理。
     *
     * @return array
     */
    private function noReplyActions()
    {
        $all = array_values((array) config('constants.AUTO_REPLY.CLOSE_ACTION'));

        return array_values(array_diff(
            $all,
            (array) config('constants.AUTO_REPLY.CLOSE_ACTION_REPLIED'),
            (array) config('constants.AUTO_REPLY.CLOSE_ACTION_GREW')
        ));
    }

    /**
     * 認不得的 close_action 有幾張
     *
     * 正常情況是 0。會有數字只有兩種可能：上線前開的舊單（不回填，所以
     * `close_action` 是 null —— 那種不會進這裡），或是**新增了一種處理方式
     * 卻忘了歸類**。後者會讓三段的數字加起來少掉幾張，所以寧可印出來。
     *
     * @param array $counts
     * @return int
     */
    private function unknownCount(array $counts)
    {
        $known = array_merge(
            (array) config('constants.AUTO_REPLY.CLOSE_ACTION_GREW'),
            $this->noGrewButReplied(),
            $this->noReplyActions()
        );

        $unknown = 0;

        foreach ($counts as $action => $total) {
            if (!in_array((int) $action, array_map('intval', $known), true)) {
                $unknown += (int) $total;
            }
        }

        return $unknown;
    }

    /**
     * 「未分類」那一行（平常不印）
     *
     * @param array $report
     * @param array $stat
     * @return string
     */
    private function unknownLine(array $report, array $stat)
    {
        if ((int) Arr::get($stat, 'unknown') < 1) {
            return '';
        }

        return strtr((string) Arr::get($report, 'UNKNOWN'), [
            '{count}' => (int) Arr::get($stat, 'unknown'),
        ]);
    }

    /**
     * 「此刻還掛著幾張」那一行
     *
     * ⚠ 跟「還沒處理完」是**兩個不同的數字**：那個是昨天那批的殘留，
     * 這個是現在總共欠多少（跟日期無關）。沒單的那天也要印 ——
     * 那天沒事不代表沒有舊帳。
     *
     * @param array $report
     * @param array $stat
     * @return string
     */
    private function openLine(array $report, array $stat)
    {
        return strtr((string) Arr::get($report, 'OPEN'), [
            '{count}' => (int) Arr::get($stat, 'open'),
        ]);
    }

    /**
     * 把指定那幾種的張數加起來
     *
     * @param array $counts  close_action => 張數
     * @param array $actions 要加總哪幾種
     * @return int
     */
    private function sumOf(array $counts, array $actions)
    {
        $total = 0;

        foreach ($actions as $action) {
            $total += (int) Arr::get($counts, (int) $action, 0);
        }

        return $total;
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
            $result = $this->supportGroup->send($text, null, null, self::NOTICE_TYPE);

            if (filled(Arr::get((array) $result, 'result'))) {
                return true;
            }

            Log::warning('求助單每日統計未送達', ['response' => $result]);

            return false;
        } catch (\Exception $e) {
            Log::error('求助單每日統計送出失敗', ['error' => $e->getMessage()]);

            return false;
        }
    }
}

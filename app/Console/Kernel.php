<?php

namespace App\Console;

use App\Services\DailyRateService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // 每日凌晨 1 點標記前一天的曠工
        $schedule->command('attendance:mark-absent')->dailyAt('01:00');

        /*
         * 每天早上 7:00 私訊今日班表 —— 勾選的人收完整班表、有班的人收自己那份。
         *
         * ⚠ **平假日都發**（需求方指定）：假日沒排班時完整班表那則會寫「今天沒有
         * 任何排班」。群組裡完全沒動靜時，分不出是「今天本來就沒班」還是
         * 「排程又壞了」。
         *
         * ⚠ **排在待接手清單（07:30）之前是刻意的**（需求方 2026-10-06 指定順序）：
         * 先知道今天誰在，再看要接什麼。
         */
        $schedule->command('shift:notify-daily')->dailyAt('07:00')->withoutOverlapping();

        /*
         * 每天早上 7:30 把還沒人處理的求助單發到內部群組，tag 當天早班接手。
         *
         * ⚠ 存在的理由是**大夜班目前沒有人排班** —— 深夜的超時提醒 tag 不到
         * 任何人，那些問題整晚沒有人接手。大夜班補上人之後這則仍然有用
         * （交接本來就該有），但緊迫性會降低。
         */
        $schedule->command('ticket:handover')->dailyAt('07:30')->withoutOverlapping();

        /*
         * 每天早上 8:00 私訊任務卡狀況 —— 同仁收自己的，勾選的人收全部人的。
         *
         * 排在待接手清單（07:30）之後、提醒統計（08:30）之前：
         * 四則主動訊息錯開，不會在同一分鐘收到一串。
         */
        $schedule->command('task:notify-daily')->dailyAt('08:00')->withoutOverlapping();

        /*
         * 每天早上 8:30 把前一天的求助單超時提醒統計私訊給指定主管。
         *
         * 排在班表（07:00）、待接手（07:30）、任務卡（08:00）之後、
         * 匯率報價（09:00）之前 —— 主動訊息錯開，不會在同一分鐘收到一串。
         *
         * ⚠ 沒有任何提醒的日子也會發（寫「昨天沒有超時」）：安靜不動時
         * 分不出「昨天沒事」還是「排程壞了」。
         */
        $schedule->command('remind:report')->dailyAt('08:30')->withoutOverlapping();

        /*
         * 待追蹤事項統計：每天 10:30 報前一天。
         *
         * ⚠ 刻意跟前面那幾則錯開（07:30 待接手、08:00 班表、08:30 超時統計、
         * 09:00 匯率、09:30 VM 繳款、10:00 餘點同步）—— 擠在一起的通知
         * 沒有人會細看。時間同時寫在 `constants.FOLLOWUP.REPORT_AT`，
         * 那是設定頁顯示用的字，改這裡要一起改。
         */
        $schedule->command('followup:report')->dailyAt('10:30')->withoutOverlapping();

        /*
         * 打卡週報表：每週一 11:00 發上週一～上週日。
         *
         * ⚠ 統計的是**上一週**，不是這一週 —— 當週還沒過完，統計沒有意義。
         *
         * 11:00 是需求方指定的。刻意排在早上那一串主動訊息（07:00～08:30）
         * 之後：報表是「回頭看」的東西，不該跟當天要做的事擠在一起。
         */
        $schedule->command('attendance:report --type=weekly')
            ->weeklyOn(1, '11:00')
            ->withoutOverlapping();

        /*
         * 打卡月報表：每月 1 號 11:00 發上個月整個月。
         *
         * ⚠ 1 號剛好是週一時，這則會跟週報同一分鐘送出 —— 兩則內容不同
         * （一則上週、一則上個月），所以是對的，不是重複發送。
         */
        $schedule->command('attendance:report --type=monthly')
            ->monthlyOn(1, '11:00')
            ->withoutOverlapping();

        /*
         * 超時提醒的週報表與月報表。
         *
         * ⚠ **時間跟日報一致，都是 08:30**（需求方 2026-10-10 調整，原本是 11:30）。
         *
         * 代價是週一會在同一分鐘送出日報與週報兩則；1 號又多一則月報，
         * 1 號剛好是週一時三則一起到。那是需求方要的「時間一致」——
         * 三則內容不同（昨天／上週／上個月），不是重複發送。
         */
        $schedule->command('remind:report --type=weekly')
            ->weeklyOn(1, '08:30')
            ->withoutOverlapping();

        $schedule->command('remind:report --type=monthly')
            ->monthlyOn(1, '08:30')
            ->withoutOverlapping();

        // 每日凌晨 2 點清理 7 天前的 Telegram 訊息
        $schedule->command('telegram:purge')->dailyAt('02:00');

        // 每分鐘送出已到期的預約群發公告（withoutOverlapping 避免上一輪還在送就再跑一次）
        $schedule->command('telegram:send-scheduled')->everyMinute()->withoutOverlapping();

        // 每分鐘提醒超時未回答的自動回覆求助單（withoutOverlapping 避免同一張單被提醒兩次）
        $schedule->command('auto-reply:remind')->everyMinute()->withoutOverlapping();

        /*
         * 每分鐘把「轉人工後靜置夠久」的對話勾回自動回覆（需求方 2026-11-07）。
         *
         * ⚠ **一定要每分鐘**：靜置時間是關閉的人當場自己填的（最短 5 分鐘），
         * 排程間隔等於額外的延遲。跑五分鐘一次的話，填 5 分鐘的那一筆
         * 實際上要等最多 10 分鐘。
         *
         * ⚠ `withoutOverlapping`：上一輪還在發通知就再跑一次的話，同一批紀錄
         * 會被撈到兩次（狀態是處理完才改的），群組會收到兩則一樣的。
         *
         * ⚠ **不要改成在關閉時排一個延遲 Job**：本專案 `QUEUE_CONNECTION=sync`
         * （`dispatch()` 當場跑完、不進背景），delay 不會生效。而且靜置是
         * 「對話最後一則訊息」的滾動條件，不是固定延遲 —— 關閉當下算不出
         * 正確的到期時間。
         *
         * 功能開關預設關著（`auto_reply_resume.enabled`），指令自己會短路，
         * 所以掛排程不等於開始改群組的開關。
         */
        $schedule->command('auto-reply:resume')->everyMinute()->withoutOverlapping();

        /*
         * 每分鐘檢查交接窗口（需求方 2026-11-08）。
         *
         * ⚠ **一定要每分鐘**：提醒的時間點是「下一班回訊開始前 5 分鐘」這種
         * 相對時刻，而四個班別的交接點分佈在一天的不同分鐘。間隔再長就會偏掉
         * —— 跑五分鐘一次的話，12:55 該提醒的可能 12:58 才發，而那時下一班
         * 已經快上線了。
         *
         * ⚠ `withoutOverlapping`：上一輪還在發就再跑一次的話，同一筆會被催
         * 兩次（`remind_count` 與 `last_reminded_at` 是發完才更新的）。
         *
         * ⚠ **這支沒有次數上限**（需求方指定「催到他完成交接事項」），
         * 所以忘記交接的那一筆會一直被撈到。控制吵不吵的是設定頁的間隔
         * （預設 30 分鐘），不是這裡的排程頻率。
         *
         * 總開關預設**開**（跟其他功能相反，需求方指定），但沒設定交接話題時
         * 服務自己會短路並記 warning —— 在主區催一件無法被滿足的事比不催更糟。
         */
        $schedule->command('shift:handover-remind')->everyMinute()->withoutOverlapping();

        /*
         * 求助單逾時升級：催到第 N 次還沒人處理就打電話把當班人叫起來
         * （需求方 2026-11-08）。
         *
         * ⚠ **一定要每分鐘**：撥號的重試間隔是 5 分鐘，而且「撥滿三通就改
         * 私訊勾選清單」要盡快發（需求方指定不等）。排程間隔等於那兩件事的
         * 額外延遲。
         *
         * ⚠ `withoutOverlapping` **特別重要**：這支會開 Python 子程序去撥號，
         * 一通響 30 秒、最多撥給每個當班的人 —— 整輪很容易超過一分鐘。
         * 重疊的話同一張單會被撥兩次，而**連撥是 Telegram 判定 spam 的
         * 主要行為樣態**，封號的是我們自己的 userbot 帳號。
         *
         * ⚠⚠ **鎖要自己設過期時間（10 分鐘），不能用預設的 1440。**
         *
         * `withoutOverlapping()` 不帶參數時鎖 **24 小時**。而這支是所有排程裡
         * 最可能跑超過一分鐘的（開子程序撥號、一通響 30 秒、逐人撥），
         * 也因此最可能被 OOM、部署、重開機砍掉 —— 被砍掉的 process 不會釋放鎖，
         * **升級就會整整停擺一天**，而那是「客人已經等了快兩小時」的最後一道防線。
         *
         * 10 分鐘足夠蓋過最壞情況（3 個當班 × 30 秒 × 幾張單），
         * 又讓意外中斷在十分鐘內自己恢復。
         *
         * 總開關預設關（`ticket_escalation.enabled`），服務自己會短路 ——
         * 所以掛排程不等於開始撥號。
         */
        $schedule->command('ticket:escalate')->everyMinute()->withoutOverlapping(10);

        /*
         * 每分鐘把登入紀錄私訊通知給帳號本人（需求方 2026-11-05）。
         *
         * ⚠ **一定要每分鐘**：排程間隔等於通知的延遲，而這是安全通知 ——
         * 「有人登入了你的帳號」晚五分鐘才說，能反應的時間就少了五分鐘。
         *
         * ⚠ **不要改成在登入當下同步發**：`TelegramBotService` 的 client 是
         * `['timeout' => 10]`，而本專案 `QUEUE_CONNECTION=sync`（`dispatch()`
         * 當場跑完、不進背景）—— Telegram 慢的時候使用者按下登入會卡 10 秒。
         *
         * ⚠ `withoutOverlapping`：上一輪還在發就再跑一次的話，同一批紀錄會被
         * 撈到兩次（`notified_at` 是處理完才標的），同仁會收到兩則一樣的。
         *
         * 沒有未通知的紀錄時 Service 自己短路（開關關掉時連 DB 都不查），
         * 所以每分鐘跑不等於每分鐘都在查一堆東西。
         */
        $schedule->command('login:notify')->everyMinute()->withoutOverlapping();

        /*
         * 每分鐘問當班人員要不要追蹤那些說了「稍等」的事項。
         *
         * ⚠ `withoutOverlapping` 同上：上一輪還在送就再跑一次，同一筆會
         * 被問兩次而計次只推進一次。
         *
         * 功能開關預設關著（`followup.enabled`），指令自己會短路，
         * 所以掛排程不等於開始發訊息。
         */
        $schedule->command('followup:ask')->everyMinute()->withoutOverlapping();

        /*
         * 預約提醒（內部群組 tag 機器人排的）。
         *
         * ⚠ **一定要每分鐘**：時間是使用者自己指定的，間隔再長就會偏掉 ——
         * 說好 14:00，14:05 才跳出來的提醒已經失去意義。
         */
        $schedule->command('reminder:send')->everyMinute()->withoutOverlapping();

        /*
         * 每分鐘把安靜滿一段時間的任務卡異動合併通知到「任務卡專區」話題
         * （需求方 2026-11-01）。
         *
         * ⚠ **一定要每分鐘**：它是「合併窗口到了就發」的輪詢 —— 排程間隔
         * 等於通知的額外延遲。設成每五分鐘的話，3 分鐘的窗口實際會變成
         * 最多 8 分鐘才發得出去。
         *
         * ⚠ `withoutOverlapping`：上一輪還在發就再跑一次的話，同一批活動
         * 紀錄會被撈到兩次（`notified_at` 是發完才標的），群組會收到兩則。
         *
         * 沒有任何待通知的異動時 Service 自己短路，所以每分鐘跑不等於
         * 每分鐘都在查一堆東西。
         */
        $schedule->command('task:notify-changes')->everyMinute()->withoutOverlapping();

        /*
         * 每分鐘查監聽中的錢包有沒有新的 TRC20 轉帳（2026-10-27）。
         *
         * ⚠ `withoutOverlapping` 一定要：有要通知的那幾輪會跑一次 headless
         * Chrome 截圖（約 10 秒），錢包多的時候整輪可能超過一分鐘。
         * 重疊的話同一批會被判斷兩次 —— 唯一索引擋得住重複寫入，
         * 但會白打一堆 TronGrid、也白截一次圖。
         *
         * 功能開關預設關著（`wallet_notice.enabled`），服務自己會短路，
         * 所以掛排程不等於開始發訊息。
         */
        $schedule->command('wallet:watch-transfers')->everyMinute()->withoutOverlapping();

        /*
         * 打卡提醒：上班前 15 分鐘、下班後 30 分鐘起每 30 分鐘一次
         * （需求方 2026-10-29）。
         *
         * ⚠ **一定要每分鐘**：提醒的時間點是「上班前 15 分」這種相對時刻，
         * 每個人的班別不同、分佈在一天的任何一分鐘。間隔再長就會偏掉 ——
         * 跑五分鐘一次的話，08:45 該提醒的人可能 08:48 才收到，
         * 而那時他已經在打卡了。
         *
         * ⚠ `withoutOverlapping`：這支會逐人送私訊，人多時可能跑超過一分鐘。
         * 重疊的話同一則會被判斷兩次 —— 狀態表的唯一索引擋得住重複寫入，
         * 但會重複發訊息給同一個人。
         *
         * ⚠ **沒有功能開關**：需求方指定不能關（會關掉它的人正好最需要它）。
         * 不想收的唯一辦法是請他的班別不要排 —— 那本來就該提醒。
         */
        $schedule->command('attendance:remind')->everyMinute()->withoutOverlapping();

        /*
         * VM 帳單：**月底最後兩天先產生下個月的**（需求方 2026-10-30）。
         *
         * ⚠ 原本只有 1 號那一次。繳款通知是每天 09:30 查「應收日 ≤ 今天+2」
         * 的未繳帳單 —— 但 30 號那天**下個月的帳單還不存在**，所以繳費日在
         * 1、2 號的客戶永遠收不到提前通知，最多只能在當天才收到。
         *
         * ⚠ `when()` 算的是「這個月的最後兩天」，不是寫死 30、31 號 ——
         * 二月只有 28 天，寫死的話二月永遠不會跑。
         *
         * ⚠ 兩次都跑是刻意的，**不是重複**：
         *
         * | 時機 | 產哪個月 | 補什麼 |
         * |---|---|---|
         * | 月底最後兩天 | 下個月 | 1、2 號繳費的提前通知 |
         * | 1 號 | 當月 | 月底那次失敗、或機器是月中才開機的 |
         *
         * 已經有的帳單會被 `generateMonthlyBillings()` 跳過，所以重跑無害。
         */
        $schedule->command('vm:generate-billing --next')
            ->dailyAt('00:05')
            ->when(function () {
                return now()->day > now()->daysInMonth - 2;
            })
            ->withoutOverlapping();

        // 每月 1 號凌晨 0 點補一次當月的（理由見上面那段）
        $schedule->command('vm:generate-billing')->monthlyOn(1, '00:00');

        // 每日上午 9 點在內部支援群組報匯率。週末假日照報 —— 每天都要有匯率
        $schedule->command('rate:ask')->dailyAt('09:00');

        /*
         * 沒人決定就每 30 分鐘提醒一次，到當天結束為止。
         *
         * 從 09:30 開始（報價後半小時才第一次催），23:59 之後不再提醒 ——
         * 跨過午夜就是新的一天，該報的是新匯率而不是繼續催昨天的。
         */
        $schedule->command('rate:remind')->everyThirtyMinutes()->between('09:30', '23:59')->withoutOverlapping();

        /*
         * 每日上午 9:30 發虛擬機繳款通知 —— 未收的給客戶、待審核的催內部審核。
         *
         * 排在匯率報價（09:00）之後、餘點告警（10:00）之前：三則對客訊息
         * 錯開時間，客戶不會在同一分鐘收到一串。
         */
        $schedule->command('vm:send-payment-notice')->dailyAt('09:30')->withoutOverlapping();

        /*
         * 同步各站台系統餘點，低於門檻發告警。
         *
         * ⚠ **不是固定時間，是「決定匯率之後的下一個整點」**（需求方 2026-10-18）。
         *
         * 原本固定 10:00。但匯率 09:00 才問，這一小時沒決定好的話，告警就少了
         * 第二則補點訊息（沒有匯率組不出來）—— 客戶收到「點數不足」卻不知道
         * 要匯多少。所以改成每個整點都來問一次，由
         * `DailyRateService::claimCreditAlertSlot()` 決定這一刻該不該發。
         *
         * ⚠ 判斷**不寫在指令裡**：手動跑 `station:sync-credit`（含 --dry-run）
         * 要能隨時執行，不該被「現在不是那個整點」擋掉。
         *
         * withoutOverlapping：逐站打主系統 API，站台多的時候可能跑超過一輪
         */
        $schedule->command('station:sync-credit')
            ->hourly()
            ->when(function () {
                return app(DailyRateService::class)->claimCreditAlertSlot();
            })
            ->withoutOverlapping();

        $this->scheduleQueueWorker($schedule);
    }

    /**
     * 佇列 worker（用排程器代替常駐 service）
     *
     * 這台機器沒有 systemd service 也沒有 supervisor，而 `schedule:run`
     * 本來就每分鐘在跑 —— 所以 worker 掛在這裡，不必另外請人裝東西。
     *
     * 每分鐘起一個、跑 55 秒自己退場，下一分鐘的接手，等於常駐；
     * 交接那幾秒的空窗，進來的工作會等到下一輪才處理。
     *
     * ⚠ **不要改用 `--stop-when-empty`**：那個做完就退出，下一批要等到
     * 下一分鐘才開始 —— 客人最多等 60 秒才收到自動回覆，太慢。
     * `--max-time` 是「待命 55 秒」，有工作進來立刻處理。
     *
     * ⚠ `--tries=1` 要跟 `AutoReplyJob::$tries` 一致（失敗不重試是刻意的，
     * 理由見該類別註解）；`--timeout=180` 要比 Job 的 `$timeout = 120` 大，
     * 讓 Job 有機會自己逾時並被記錄下來。
     *
     * `withoutOverlapping(2)` 帶 2 分鐘過期 —— worker 被 kill 時鎖不會卡住。
     *
     * @param Schedule $schedule
     * @return void
     */
    private function scheduleQueueWorker(Schedule $schedule)
    {
        /*
         * sync 不是真的佇列（工作當場同步跑完），對它下 `queue:work`
         * 沒有意義。所以切換只需要改 `.env` 一個地方：
         * QUEUE_CONNECTION=database 之後，worker 自動開始排程。
         */
        if (config('queue.default') === 'sync') {
            return;
        }

        $schedule->command('queue:work --max-time=55 --tries=1 --timeout=180')
            ->everyMinute()
            ->withoutOverlapping(2)
            ->runInBackground()
            /*
             * `runInBackground()` 預設把輸出丟進 /dev/null —— 那樣 worker
             * 自己起不來（PHP fatal、找不到 autoload…）會完全無聲無息。
             *
             * 工作本身的失敗有 Log 與 failed_jobs 接著，這個檔收的是
             * 「worker 這個行程」的狀況。沒有工作時 `queue:work` 不輸出東西，
             * 所以平常不會長大。
             */
            ->appendOutputTo(storage_path('logs/queue-worker.log'));
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}

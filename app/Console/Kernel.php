<?php

namespace App\Console;

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

        // 每月 1 號凌晨 0 點產生 VM 帳單
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

        // 每日上午 10 點同步各站台系統餘點，低於門檻發告警
        // withoutOverlapping：逐站打主系統 API，站台多的時候可能跑超過一輪
        $schedule->command('station:sync-credit')->dailyAt('10:00')->withoutOverlapping();

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

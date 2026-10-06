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
         * 每天早上 8:00 私訊今日班表 —— 主管收完整班表、有班的人收自己那份。
         *
         * ⚠ **平假日都發**（需求方指定）：假日沒排班時主管那則會寫「今天沒有
         * 任何排班」。群組裡完全沒動靜時，分不出是「今天本來就沒班」還是
         * 「排程又壞了」。
         *
         * 排在 09:00 匯率報價之前 —— 上班前先知道今天誰在。
         */
        $schedule->command('shift:notify-daily')->dailyAt('08:00')->withoutOverlapping();

        /*
         * 每天早上 8:30 把前一天的求助單超時提醒統計私訊給指定主管。
         *
         * 排在班表通知（08:00）之後、匯率報價（09:00）之前 ——
         * 三則主動訊息錯開，主管不會在同一分鐘收到一串。
         *
         * ⚠ 沒有任何提醒的日子也會發（寫「昨天沒有超時」）：安靜不動時
         * 分不出「昨天沒事」還是「排程壞了」。
         */
        $schedule->command('remind:report')->dailyAt('08:30')->withoutOverlapping();

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

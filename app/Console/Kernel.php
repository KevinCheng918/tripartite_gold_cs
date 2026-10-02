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

        // 每日凌晨 2 點清理 7 天前的 Telegram 訊息
        $schedule->command('telegram:purge')->dailyAt('02:00');

        // 每分鐘檢查 Telegram 未回覆訊息告警
        $schedule->command('telegram:alert')->everyMinute();

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

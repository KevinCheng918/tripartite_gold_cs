<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 登入紀錄加上「已通知」標記
 *
 * 需求方 2026-11-05：登入後要私訊通知本人（時間、IP、作業系統、瀏覽器、裝置）。
 *
 * ⚠ **為什麼要這個欄位，而不是在登入當下直接發**：
 * `TelegramBotService` 的 HTTP client 是 `['timeout' => 10]`，而本專案
 * `QUEUE_CONNECTION=sync` —— `dispatch()` 是當場跑完、不會進背景。
 * 在登入流程裡發私訊的話，Telegram 慢或掛掉時使用者按下登入會卡最多 10 秒
 * 才進得去後台。登入是所有人每天第一個動作，不能掛這種風險。
 *
 * 所以改成「登入只寫紀錄（本來就會寫），`login:notify` 每分鐘撈未通知的發」，
 * 跟 `task:notify-changes` 同一個模式。代價是通知最多慢 1 分鐘。
 */
class AddNotifiedAtToLoginLogTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('login_log', 'notified_at')) {
            return;
        }

        Schema::table('login_log', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('created_at')
                ->comment('已發送登入通知的時間；null = 還沒處理');

            /*
             * 排程每分鐘查「`notified_at` 是 null 且在回看窗口內」。
             * 跑一段時間之後絕大多數列都不是 null，所以這個索引很有選擇性。
             *
             * ⚠ MySQL 的 `IS NULL` 用得到 B-tree 索引（null 也進索引），
             * 所以這裡不需要改用「標記已處理的布林欄位」那種繞法。
             */
            $table->index('notified_at', 'login_log_notified_at_idx');
        });

        /*
         * ⚠⚠ **既有紀錄一定要回填，不能留 null。**
         *
         * 不回填的話，`login:notify` 第一次跑就會把**整個歷史**的登入紀錄
         * 全判成「還沒通知」。正式機累積的筆數可能上千 ——
         * 每個人會在同一分鐘內被私訊轟炸好幾十則幾個月前的登入，
         * 而且那些 Telegram 訊息收回不來。
         *
         * 回填成 `created_at`（而不是 `now()`）：語意上是「這筆在它發生的當下
         * 就算處理完了」，日後看這個欄位也不會以為系統在上線那一刻發過幾千則。
         */
        DB::table('login_log')->whereNull('notified_at')->update([
            'notified_at' => DB::raw('created_at'),
        ]);
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('login_log', 'notified_at')) {
            return;
        }

        Schema::table('login_log', function (Blueprint $table) {
            /*
             * ⚠ 索引要先手動丟掉再 drop 欄位。這個專案**沒有裝 doctrine/dbal**，
             * `dropColumn` 在某些 MySQL 版本會因為索引還掛著而失敗，
             * 而失敗的時機在 migration 中途 —— 比先丟索引難救。
             */
            $table->dropIndex('login_log_notified_at_idx');
            $table->dropColumn('notified_at');
        });
    }
}

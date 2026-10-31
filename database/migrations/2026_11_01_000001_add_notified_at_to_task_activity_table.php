<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 活動紀錄加上「通知發過了沒」
 *
 * 需求方 2026-11-01：任務卡有異動要通知「任務卡專區」話題，
 * 而且**同一張卡幾分鐘內的連續異動要合併成一則**。
 *
 * ⚠ **為什麼不開新的緩衝表**：要合併就得先把「還沒通知的異動」存下來，
 * 而 `task_activity` 本來就一筆一筆記著誰在什麼時候改了什麼 ——
 * 只差一個「發過了沒」的標記。另開一張表等於把同一份資料記兩遍，
 * 而兩份遲早會不一致。
 *
 * ⚠ **為什麼不用 delayed queue**：這個專案的 `QUEUE_CONNECTION` 是 `sync`
 * （見 `.env`），延遲派送根本不會生效 —— 任務會當場同步執行，等於沒有合併。
 * 所以走排程指令（`task:notify-changes`，每分鐘），那也是這個專案既有的做法。
 */
class AddNotifiedAtToTaskActivityTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('task_activity') || Schema::hasColumn('task_activity', 'notified_at')) {
            return;
        }

        Schema::table('task_activity', function (Blueprint $table) {
            /*
             * null ＝ 還沒處理過；有值 ＝ 已經發過（或判定不需要發）。
             *
             * ⚠ **索引要含 `notified_at`**：排程每分鐘都會問一次
             * 「有哪些還沒處理的」，沒有索引就是每分鐘全表掃一次活動紀錄，
             * 而那張表只會越來越大。
             */
            $table->timestamp('notified_at')->nullable()->after('changes')
                ->comment('任務卡異動通知發過了沒；null = 還沒處理');

            $table->index(['notified_at', 'task_id'], 'task_activity_notified_idx');
        });

        /*
         * ⚠ **既有紀錄一律標記成「已處理」**。
         *
         * 不標的話，排程第一次跑就會把資料庫裡所有歷史異動當成「還沒通知」，
         * 一次把幾百則舊訊息灌進群組 —— 那是上線當下最糟的事。
         *
         * 用 `created_at` 當值而不是 `now()`：這樣看紀錄的人才知道
         * 「這筆是補標的歷史資料」，而不是以為通知真的在那一刻發出去。
         */
        DB::table('task_activity')
            ->whereNull('notified_at')
            ->update(['notified_at' => DB::raw('`created_at`')]);
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('task_activity') || !Schema::hasColumn('task_activity', 'notified_at')) {
            return;
        }

        Schema::table('task_activity', function (Blueprint $table) {
            $table->dropIndex('task_activity_notified_idx');
            $table->dropColumn('notified_at');
        });
    }
}

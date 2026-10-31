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

        $this->addToComments();
    }

    /**
     * 留言也要一份「通知發過了沒」
     *
     * ⚠ **這一段在既有環境不會執行。** 這支 migration 在它被加進來之前就已經
     * 跑過了（batch 37），而 migration 跑過就不會再跑 —— 所以
     * `task_comment.notified_at` 是由
     * `2026_11_01_000003_add_notified_at_to_task_comment_table` 補的。
     *
     * 留在這裡是為了**全新環境**：那時候這一支會建好兩張表的欄位，
     * 000003 偵測到已存在就什麼都不做。兩邊都有 `hasColumn` 守衛，
     * 任何一邊先跑都不會出錯。
     *
     * ⚠ **留言不走 `logActivity()`**（這個專案刻意不把留言寫進活動紀錄，
     * 見 features/task-board.md），所以沒辦法共用上面那一欄 ——
     * 必須在 `task_comment` 自己加一個。
     *
     * ⚠ 不改成「讓留言開始寫活動紀錄」：那會讓異動紀錄那個視窗的內容跟著變，
     * 而需求方要的只是「通知」。改一個頁面的顯示內容是另一回事。
     *
     * @return void
     */
    private function addToComments()
    {
        if (!Schema::hasTable('task_comment') || Schema::hasColumn('task_comment', 'notified_at')) {
            return;
        }

        Schema::table('task_comment', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('updated_at')
                ->comment('新留言通知發過了沒；null = 還沒處理');

            $table->index(['notified_at', 'task_id'], 'task_comment_notified_idx');
        });

        // 理由同上面那一段：既有留言一律標成已處理，不然第一次跑會全部重發
        DB::table('task_comment')
            ->whereNull('notified_at')
            ->update(['notified_at' => DB::raw('`created_at`')]);
    }

    /**
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('task_comment') && Schema::hasColumn('task_comment', 'notified_at')) {
            Schema::table('task_comment', function (Blueprint $table) {
                $table->dropIndex('task_comment_notified_idx');
                $table->dropColumn('notified_at');
            });
        }

        if (!Schema::hasTable('task_activity') || !Schema::hasColumn('task_activity', 'notified_at')) {
            return;
        }

        Schema::table('task_activity', function (Blueprint $table) {
            $table->dropIndex('task_activity_notified_idx');
            $table->dropColumn('notified_at');
        });
    }
}

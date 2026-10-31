<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 留言也要一份「通知發過了沒」
 *
 * ⚠ **為什麼這一支獨立存在，而不是併在
 * `2026_11_01_000001_add_notified_at_to_task_activity_table` 裡。**
 *
 * 那一支本來就有 `addToComments()` 這一段，但它**在加進去之前就已經被執行
 * 過了**（batch 37）—— migration 跑過就不會再跑，所以那一段在既有環境
 * 永遠不會生效：`task_activity.notified_at` 有了，`task_comment.notified_at`
 * 沒有，而 `TaskRepository::getUnnotifiedComments()` 會直接丟
 * `Unknown column`，留言通知那一半整個不會動。
 *
 * ⚠ **兩支都留著是刻意的**：兩邊都有 `hasColumn` 的守衛，所以
 *
 *   - 既有環境：000001 已執行 → 這一支把欄位補上
 *   - 全新環境：000001 就會建好 → 這一支偵測到已存在、什麼都不做
 *
 * 任何一邊先跑都不會出錯。
 */
class AddNotifiedAtToTaskCommentTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('task_comment') || Schema::hasColumn('task_comment', 'notified_at')) {
            return;
        }

        Schema::table('task_comment', function (Blueprint $table) {
            $table->timestamp('notified_at')->nullable()->after('updated_at')
                ->comment('新留言通知發過了沒；null = 還沒處理');

            // 排程每分鐘問一次「有哪些還沒處理的」，沒索引就是每分鐘全表掃
            $table->index(['notified_at', 'task_id'], 'task_comment_notified_idx');
        });

        /*
         * ⚠ **既有留言一律標記成已處理。**
         *
         * 不標的話，排程第一次跑就會把資料庫裡所有歷史留言當成「新留言」，
         * 一次把幾百則通知灌進群組。
         *
         * 用 `created_at` 當值而不是 `now()`：看紀錄的人才分得出
         * 「這筆是補標的歷史資料」。
         */
        DB::table('task_comment')
            ->whereNull('notified_at')
            ->update(['notified_at' => DB::raw('`created_at`')]);
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('task_comment') || !Schema::hasColumn('task_comment', 'notified_at')) {
            return;
        }

        Schema::table('task_comment', function (Blueprint $table) {
            $table->dropIndex('task_comment_notified_idx');
            $table->dropColumn('notified_at');
        });
    }
}

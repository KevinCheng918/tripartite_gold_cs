<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 補點紀錄加上「有沒有通知到客戶」
 *
 * 需求方 2026-10-27：「有問題就顯示，可以做補傳按鈕也沒關係。」
 *
 * ⚠ 在這之前，通知沒送出去**只寫在 log**：站台沒綁群組、主站沒回訊息、
 * Telegram 拒收 —— 三種都是靜悄悄的，後台只會顯示「補點成功」，
 * 沒有人知道客戶其實什麼都沒收到。
 */
class AddNotifyStateToCreditTopupTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        Schema::table('credit_topup', function (Blueprint $table) {
            if (!Schema::hasColumn('credit_topup', 'notified_at')) {
                $table->timestamp('notified_at')->nullable()->after('reviewed_at')
                    ->comment('補點通知送出的時間；null = 沒送出去');
            }

            /*
             * ⚠ 跟 `notified_at` **是兩件事，不能合併成一個狀態欄**：
             * 「主站回的是舊格式」那一種是**訊息有送出去**（notified_at 有值）
             * 但內容格式不對 —— 兩個資訊都要留著。
             */
            if (!Schema::hasColumn('credit_topup', 'notify_issue')) {
                $table->string('notify_issue', 20)->nullable()->after('notified_at')
                    ->comment('通知有什麼問題：no_group/no_message/send_failed/old_format');
            }
        });

        $this->backfill();
    }

    /**
     * 既有的已完成紀錄一律當成「已通知」
     *
     * ⚠ **不回填的話，列表上所有歷史紀錄都會變成紅色的「未通知」** ——
     * 那是假警報，而且會訓練同仁忽略這個標示，反而讓真正沒送到的被忽略掉。
     *
     * ⚠ 這是**推論不是事實**：這一欄以前不存在，沒有任何依據能知道當初到底
     * 有沒有送到。選「當成已通知」的理由是另一邊更糟 ——
     * 標成未通知會讓人去按「補傳」，把三個月前的補點通知重新發給客戶。
     *
     * ⚠ 用 `reviewed_at` 而不是 `now()`：當成「審核當下就通知了」才對得上
     * 列表上的時間，填 `now()` 會變成一整批紀錄都在 migration 那一刻通知。
     *
     * ⚠ `notify_issue` 留空 —— 不知道就不要編一個原因出來。
     *
     * @return void
     */
    private function backfill()
    {
        DB::table('credit_topup')
            ->whereNull('notified_at')
            // 1 = STATUS_COMPLETED。只有已完成的才該有通知
            ->where('status', 1)
            ->whereNotNull('reviewed_at')
            ->update(['notified_at' => DB::raw('reviewed_at')]);
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::table('credit_topup', function (Blueprint $table) {
            $table->dropColumn(['notified_at', 'notify_issue']);
        });
    }
}

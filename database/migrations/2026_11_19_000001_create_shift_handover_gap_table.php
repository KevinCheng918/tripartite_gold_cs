<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立交接漏項表（shift_handover_gap）
 *
 * 同仁打完交接後，系統把那一班發生過的事（求助單／待追蹤／預約提醒）
 * 拿去跟他打的內容比對，沒被提到的用選單問他要不要補。這張表存的就是
 * 那一次比對的結果 —— 每一個候選項目一列。
 *
 * ⚠ **為什麼要落地而不是只發訊息**：Telegram 的 `callback_data` 只有
 * **64 bytes**，塞不下「哪一項、內容是什麼」。按鈕只帶
 * `hr:<action>:<handover_id>:<seq>`，內容按下去的時候回這裡撈。
 *
 * ⚠ **`summary` 是快照**（同 `auto_reply_ticket.question` 的理由）：
 * 按鈕可能半小時後才被按，那時求助單早就被處理掉了 ——
 * 重查會查出跟選單上不一樣的話，按的人會以為系統錯亂。
 *
 * 詳見 .claude/knowledge/features/handover-gap-review.md
 */
class CreateShiftHandoverGapTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('shift_handover_gap')) {
            return;
        }

        Schema::create('shift_handover_gap', function (Blueprint $table) {
            $table->id();

            $table->foreignId('shift_handover_id')
                ->constrained('shift_handover')
                ->cascadeOnDelete()
                ->comment('哪一次交接');

            $table->tinyInteger('source_type')->comment('見 constants.HANDOVER_REVIEW.SOURCE');

            /*
             * 來源主鍵。**固定項（主管／老闆交代）為 null** ——
             * 那一項不是從任何一張表撈出來的，是固定掛在最後的問話。
             *
             * 刻意不設外鍵：求助單被刪掉時這一列要留著（它記的是
             * 「當時問過這件事」這個事實），而且來源跨三張表，設不了。
             */
            $table->unsignedBigInteger('source_id')->nullable()->comment('來源主鍵，固定項為 null');

            $table->string('title', 200)->comment('選單上那一行的標題（快照）');
            $table->string('detail', 300)->nullable()->comment('出處與現況，例如「求助單 #123，已等 3 小時」（快照）');

            /*
             * AI 判斷交接原文有沒有提到這件事。
             *
             * ⚠ 模型掛掉時**整批記 false**（＝全部列出來），不是不發 ——
             * 少了比對只是多問幾項，漏掉才是真的出事。
             */
            $table->boolean('covered')->default(false)->comment('交接內容有沒有提到');

            $table->tinyInteger('status')->default(0)->comment('見 constants.HANDOVER_REVIEW.STATUS');

            // 選單上的編號。callback_data 只帶這個，所以同一次交接內要唯一
            $table->unsignedTinyInteger('seq')->comment('選單編號');

            $table->timestamps();

            /*
             * 按鈕按下去是「這次交接的第 N 項」—— 唯一索引同時擋掉
             * 重複寫入（同一次交接被盤兩次的話，第二次會直接失敗而不是
             * 默默多出一組編號一樣的項目）。
             */
            $table->unique(['shift_handover_id', 'seq'], 'uniq_handover_seq');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('shift_handover_gap')) {
            return;
        }

        Schema::dropIfExists('shift_handover_gap');
    }
}

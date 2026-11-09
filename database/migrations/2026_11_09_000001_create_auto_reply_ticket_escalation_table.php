<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立求助單升級紀錄表（auto_reply_ticket_escalation）
 *
 * 求助單催到第 10 次（約逾時 100 分鐘）還沒人處理時，升級成「打電話把人
 * 叫起來 ＋ 私訊」。這張表記每一次升級動作的對象與結果。
 *
 * ⚠ **不複用 `auto_reply_ticket_remind`**：那張表記的是「**群組**提醒
 * tag 到誰」。升級是另一種管道（電話、私訊），混進去之後「這張單被催了
 * 幾次」會同時包含群組與電話，超時統計的每個數字都要先問「這筆是哪一種」。
 * 同 `conversation_followup` 當初不跟 `auto_reply_ticket` 共用的理由。
 *
 * 詳見 .claude/knowledge/features/ticket-overdue-escalation.md
 */
class CreateAutoReplyTicketEscalationTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('auto_reply_ticket_escalation')) {
            return;
        }

        Schema::create('auto_reply_ticket_escalation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_reply_ticket_id')->constrained('auto_reply_ticket')->cascadeOnDelete()->comment('哪一張求助單');

            $table->tinyInteger('stage')->comment('1=打電話, 2=私訊當班人, 3=私訊勾選清單');
            $table->unsignedInteger('seq')->comment('觸發時那張單已經催到第幾次');

            /*
             * 同一階段的第幾次。
             *
             * 撥號會重試（最多 3 次、間隔 5 分鐘），所以要分得出是第幾通 ——
             * 少了它就答不出「第幾通才接到」，也沒辦法判斷還能不能再撥。
             * 私訊不重試，固定 1。
             */
            $table->unsignedInteger('attempt')->default(1)->comment('同一階段的第幾次（撥號 1-3，私訊固定 1）');

            // nullOnDelete 的欄位必須 nullable；帳號刪掉後靠 user_name 還看得出是誰
            $table->foreignId('user_id')->nullable()->constrained('user')->nullOnDelete()->comment('通知／撥打的對象');
            $table->string('user_name', 100)->nullable()->comment('對象名稱快照');

            /*
             * ⚠ **「成功」只代表動作送出去了，不代表人被叫醒或事情被處理。**
             *
             * 電話接起來一定會斷（我們不串音訊），所以通話狀態不可靠；
             * 私訊也讀不到已讀。真正的「處理了沒」一律看求助單自己的 status。
             */
            $table->tinyInteger('result')->comment('1=成功, 2=送不出去（沒綁定／沒 username）, 3=被拒絕（隱私設定等）');
            $table->string('detail', 255)->nullable()->comment('錯誤碼或通話結果');

            $table->timestamps();

            // 查「這張單升級到哪了、撥過幾通」
            $table->index(['auto_reply_ticket_id', 'stage'], 'ticket_escalation_ticket_stage_index');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('auto_reply_ticket_escalation');
    }
}

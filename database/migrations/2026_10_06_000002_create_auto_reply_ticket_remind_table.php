<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立求助單提醒紀錄表（auto_reply_ticket_remind）
 *
 * 每送出一次提醒、每 tag 到一個人，就是一列。
 *
 * ⚠ **為什麼不能只靠 `auto_reply_ticket.remind_count`**：那個欄位只知道
 * 「這張單被催了幾次」，答不出「小明這個月被催了幾次」。**當班人員會隨時段換人**
 * ——同一張單催五次可能 tag 到三組不同的人，需求方要的每日統計是「按單 ＋ 按人
 * 都要」（2026-10-06 確認），所以每次 tag 到誰一定要逐筆落地。
 *
 * ⚠ **tag 不到人的時候也要記一列**（`user_id` 為 null）：那代表「催了但沒人被
 * 叫到」，是最該被看見的狀況 —— 不記的話統計上會看起來像那次提醒沒發生。
 */
class CreateAutoReplyTicketRemindTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('auto_reply_ticket_remind')) {
            return;
        }

        Schema::create('auto_reply_ticket_remind', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auto_reply_ticket_id')->constrained('auto_reply_ticket')->cascadeOnDelete()->comment('哪一張求助單');
            $table->unsignedSmallInteger('seq')->comment('這張單的第幾次提醒（1 起算）');
            /*
             * 被 tag 到的人。
             *
             * nullable 的兩個理由：tag 不到人時也要記一列（見類別註解），
             * 以及帳號被刪掉時這筆統計要留著 —— 所以是 nullOnDelete，
             * 而 nullOnDelete 必須搭 nullable 欄位。
             */
            $table->foreignId('user_id')->nullable()->constrained('user')->nullOnDelete()->comment('被 tag 的人');
            $table->tinyInteger('stage')->default(1)->comment('1=只 tag 當班人員, 2=同時 tag 主管與老闆, 3=已達上限的最後一則');
            $table->timestamps();

            // 每日統計是「某一天的所有提醒」，所以時間要能單獨走索引
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['auto_reply_ticket_id', 'seq']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('auto_reply_ticket_remind');
    }
}

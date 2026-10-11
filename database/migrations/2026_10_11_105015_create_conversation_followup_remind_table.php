<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立待追蹤事項的提醒紀錄表（conversation_followup_remind）
 *
 * 每問當班人員一次、每 tag 到一個人，就是一列。結構照
 * `auto_reply_ticket_remind` 抄，理由也一樣：
 *
 * ⚠ **`conversation_followup.remind_count` 答不出「小明被問了幾次」**——
 * 當班人員會隨時段換人，同一筆事項問五次可能 tag 到三組不同的人。
 *
 * ⚠ **tag 不到人的時候也要記一列**（`user_id` 為 null）：那代表「問了但
 * 沒有人被叫到」，是最該被看見的狀況 —— 不記的話統計上會看起來像那次沒發生。
 *
 * ⚠ **不共用 `auto_reply_ticket_remind`**：它的 `auto_reply_ticket_id` 是
 * 有外鍵約束的欄位，塞別種單的 id 進去會直接違反外鍵；改成可為 null 再加一個
 * type 欄位的話，既有統計的每一句 SQL 都要跟著加條件。
 */
class CreateConversationFollowupRemindTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('conversation_followup_remind')) {
            return;
        }

        Schema::create('conversation_followup_remind', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_followup_id')->constrained('conversation_followup')->cascadeOnDelete()->comment('哪一筆待追蹤事項');
            $table->unsignedSmallInteger('seq')->comment('這筆事項的第幾次提醒（1 起算）');
            /*
             * 被 tag 到的人。nullable 的兩個理由：tag 不到人時也要記一列
             * （見類別註解），以及帳號被刪掉時這筆統計要留著 —— 所以是
             * nullOnDelete，而 nullOnDelete 必須搭 nullable 欄位。
             */
            $table->foreignId('user_id')->nullable()->constrained('user')->nullOnDelete()->comment('被 tag 的人');
            $table->tinyInteger('stage')->default(1)->comment('1=只 tag 當班人員, 2=同時 tag 主管與老闆, 3=已達上限的最後一則');
            $table->timestamps();

            // 統計是「某一段期間的所有提醒」，時間要能單獨走索引
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['conversation_followup_id', 'seq']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('conversation_followup_remind');
    }
}

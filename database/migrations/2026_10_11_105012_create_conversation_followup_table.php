<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立待追蹤事項表（conversation_followup）
 *
 * 有人在對話裡說「稍等／晚點給你」就記一筆，隔一段時間問當班人員要不要追。
 *
 * ⚠ **為什麼不共用 `auto_reply_ticket`**：那張表的狀態機講的是「這題答出來了
 * 沒有」（PENDING → ANSWERED → REPLIED / SAVED），這張講的是「對方回來了沒有」
 * —— 方向相反。硬共用會讓求助單統計的每個數字都要先問「這筆是哪一種」。
 *
 * ⚠ **`source` 分客人承諾與我方承諾**（需求方 2026-10-11）：兩種都要記，
 * 但按下「要追蹤」之後的動作完全不同 —— 客人承諾是發訊息問客人，
 * 我方承諾只在群組 tag 當班人員，絕不回頭問客人。
 */
class CreateConversationFollowupTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('conversation_followup')) {
            return;
        }

        Schema::create('conversation_followup', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained('telegram_group')->cascadeOnDelete()->comment('哪個對話');
            /*
             * 說那句話的訊息。
             *
             * nullable + nullOnDelete：訊息會被 TTL 清理（telegram:purge）掉，
             * 但那時候這筆事項可能還掛著 —— 訊息沒了也要留著追。
             */
            $table->foreignId('telegram_message_id')->nullable()->constrained('telegram_message')->nullOnDelete()->comment('說稍等的那一則');
            $table->tinyInteger('source')->default(1)->comment('1=客人承諾, 2=我方承諾');
            // 訊息被清掉之後還要看得出是誰說的，所以存快照而不是只靠關聯
            $table->string('sender_name', 100)->nullable()->comment('誰說的');
            $table->foreignId('sender_user_id')->nullable()->constrained('user')->nullOnDelete()->comment('我方承諾時對應的後台帳號');
            $table->string('wait_text', 500)->nullable()->comment('那句原話（快照）');
            // 詞表要調整時，靠這個欄位才知道哪幾個詞一直在誤判
            $table->string('matched_keyword', 50)->nullable()->comment('命中哪個等待詞');
            $table->timestamp('wait_at')->nullable()->comment('計時起點，對方每說一次話就往後推');
            $table->tinyInteger('status')->default(1)->comment('1=等待中, 2=已追問, 3=對方回應, 4=人工結案, 5=放棄, 6=逾期收掉');
            /*
             * 對方說了第幾次「稍等」。
             *
             * 這是當班人員按按鈕的依據 ——「第一次說稍等」跟「說了第三次、
             * 我們也追問過兩次」要做的決定完全不同。也是唯一能看出
             * 「習慣性拖延」的訊號。
             */
            $table->unsignedSmallInteger('wait_count')->default(1)->comment('對方說了第幾次稍等');
            $table->unsignedSmallInteger('ask_round')->default(0)->comment('已經追問幾輪（不設上限，只顯示）');
            $table->unsignedBigInteger('ask_message_id')->nullable()->comment('群組那則詢問訊息的 telegram message_id');
            $table->unsignedSmallInteger('remind_count')->default(0)->comment('問了當班人員幾次');
            $table->timestamp('last_reminded_at')->nullable()->comment('最後一次問當班人員的時間');
            // 「當班人員沒反應」要有憑據，所以誰按的、什麼時候按都要留
            $table->foreignId('decided_by')->nullable()->constrained('user')->nullOnDelete()->comment('誰按的按鈕／誰手動處理');
            $table->timestamp('decided_at')->nullable()->comment('做決定的時間');
            $table->timestamps();

            // 每分鐘的排程靠這個撈「該問了」的單
            $table->index(['status', 'wait_at']);
            // 去重：同一個對話、同一種來源只留一張未結案的單
            $table->index(['telegram_group_id', 'source', 'status']);
            // 每日統計看的是「昨天新開的」
            $table->index('created_at');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('conversation_followup');
    }
}

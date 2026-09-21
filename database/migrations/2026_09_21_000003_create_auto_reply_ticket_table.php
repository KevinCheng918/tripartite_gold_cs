<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立自動回覆求助單表（auto_reply_ticket）
 *
 * 題庫裡找不到答案時，系統會在內部支援群組發一則求助訊息並開一張單，
 * 自己人「引用回覆」該則訊息作答，再用按鈕決定要回覆客人／加入題庫。
 *
 * ask_message_id 是整條迴路的對應鍵 —— 內部群組同時有好幾張單在跑時，
 * 只有 Telegram 的 reply_to_message.message_id 能把答案精準對回原本那張單。
 *
 * question 存的是客人問題的快照：客人事後編輯訊息不影響已經送出去問的內容。
 */
class CreateAutoReplyTicketTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('auto_reply_ticket')) {
            return;
        }

        Schema::create('auto_reply_ticket', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_group_id')->constrained('telegram_group')->cascadeOnDelete()->comment('客人所在群組');
            // 訊息有 TTL 7 天清理，被清掉時工單要留著，所以是 nullOnDelete
            $table->foreignId('message_id')->nullable()->constrained('telegram_message')->nullOnDelete()->comment('客人那則訊息');
            $table->text('question')->comment('客人問題原文（快照）');
            $table->unsignedBigInteger('ask_message_id')->nullable()->comment('求助訊息在內部群組的 telegram message_id');
            $table->text('answer')->nullable()->comment('自己人的回答原文');
            $table->string('answered_by', 100)->nullable()->comment('回答者的 Telegram 顯示名稱');
            $table->dateTime('answered_at')->nullable()->comment('回答時間');
            $table->tinyInteger('remind_count')->default(0)->comment('0=未提醒, 1=已 tag 當班人員, 2=已 tag 主管與老闆');
            $table->dateTime('last_reminded_at')->nullable()->comment('最後一次提醒時間');
            $table->tinyInteger('status')->default(1)->comment('1=待回答, 2=已回答待處理, 3=已回覆客人, 4=已加入題庫, 0=忽略');
            // 不設外鍵：只用來回溯這張單產生了哪一則題目，題庫被刪掉時留著舊 id 也無妨
            $table->unsignedBigInteger('quick_reply_item_id')->nullable()->comment('加入題庫後的題目 id');
            $table->timestamps();

            $table->index('ask_message_id');
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('auto_reply_ticket')) {
            return;
        }

        Schema::dropIfExists('auto_reply_ticket');
    }
}

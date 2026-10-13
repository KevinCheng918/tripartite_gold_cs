<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 預約提醒（在內部群組 tag 機器人說「什麼時候提醒我什麼」）
 *
 * 時間到了把內容原文發回**同一個話題**，所以 chat_id 與 thread_id 都要存 ——
 * 發到別的話題等於那則提醒沒人看得到。
 *
 * ⚠ **內容存原文**（含 `@帳號`）。Telegram 會在送出時自己把 @xxx 變成 mention，
 * 所以要 tag 誰完全由預約的人決定，系統不必也不該去解析那些帳號。
 */
class CreateScheduledReminderTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('scheduled_reminder')) {
            return;
        }

        Schema::create('scheduled_reminder', function (Blueprint $table) {
            $table->id();

            /*
             * ⚠ 群組與話題一起存。
             *
             * 只存 thread_id 的話，哪天內部群組換了 chat_id（開啟話題功能會讓
             * Telegram 把群組升級成 supergroup，id 真的會變 —— 2026-10-06 踩過）
             * 那些待發的提醒會發到一個不存在的地方，而且沒有任何錯誤。
             */
            $table->bigInteger('chat_id')->comment('發回哪個群組');
            $table->bigInteger('message_thread_id')->nullable()->comment('發回哪個話題，null = 群組主區');

            // 原文送出，Telegram 自己會把 @帳號 變成 mention
            $table->text('content')->comment('提醒內容（原文，含 @帳號）');

            $table->dateTime('remind_at')->comment('什麼時候發');

            // 見 constants.REMINDER.STATUS
            $table->tinyInteger('status')->default(1)->comment('1=待發送 2=已發送 3=已取消');
            $table->timestamp('sent_at')->nullable()->comment('實際發出的時間');

            /*
             * 誰預約的。存顯示名而不是關聯後台帳號：在群組裡操作的人不一定
             * 綁過後台帳號，而這個欄位的用途只是讓清單看得出「這是誰排的」。
             */
            $table->string('created_by', 100)->nullable()->comment('預約的人（Telegram 顯示名）');
            $table->bigInteger('created_by_telegram_id')->nullable()->comment('預約的人的 Telegram id');

            $table->timestamps();

            /*
             * 排程每分鐘問一次「有沒有該發的」，條件就是這兩欄 ——
             * 這張表會一直長大，沒有索引那支排程會越跑越慢。
             */
            $table->index(['status', 'remind_at'], 'scheduled_reminder_status_time_index');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('scheduled_reminder');
    }
}

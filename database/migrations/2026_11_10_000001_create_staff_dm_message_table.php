<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立同仁私訊索引表（staff_dm_message）
 *
 * 機器人每發一則同仁私訊就記一筆 message_id，**唯一用途是「將來刪得掉」**。
 *
 * ⚠⚠ **刻意不存訊息內容**。這個功能要防的就是「內部資訊留在同仁的
 * Telegram 裡」—— 把內容抄進自己的 DB 等於把要防的東西搬到另一個地方，
 * 曝光面變大而不是變小。要知道那則講了什麼，去看發送端的 log。
 *
 * ⚠ **不複用 `telegram_message`**：那張表的 `telegram_group_id` 是必填外鍵，
 * 只存群組訊息；而且它存 content、TTL 7 天，兩邊的用途與保留政策都不同。
 *
 * ⚠ `user_id` 用 nullOnDelete 而不是 cascadeOnDelete：同仁離職、帳號被刪掉
 * 之後，**那些私訊還留在他手機裡** —— 正是最需要清掉的時候。留著
 * `telegram_chat_id` 就還刪得掉，cascade 會把這個能力一起刪掉。
 *
 * 詳見 .claude/knowledge/features/telegram-dm-wipe.md
 */
class CreateStaffDmMessageTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('staff_dm_message')) {
            return;
        }

        Schema::create('staff_dm_message', function (Blueprint $table) {
            $table->id();

            // nullable 是 nullOnDelete 的前提
            $table->foreignId('user_id')->nullable()->constrained('user')->nullOnDelete()->comment('收件同仁（帳號刪除後為 null，仍可用 chat_id 刪除）');

            $table->unsignedBigInteger('telegram_chat_id')->comment('私訊的 chat_id（＝當下的 telegram_user_id 快照，之後改了也刪得掉）');
            $table->unsignedBigInteger('telegram_message_id')->comment('要刪除的目標 message_id');
            $table->string('notice_key', 50)->nullable()->comment('哪一種通知（constants.NOTICE.PERSONAL）；null＝完整版或不受管');

            $table->tinyInteger('result')->nullable()->comment('清除結果，見 constants.STAFF_DM_WIPE.RESULT；null＝還沒處理');
            $table->timestamp('wiped_at')->nullable()->comment('處理時間');

            $table->timestamp('created_at')->useCurrent()->comment('私訊送出時間（判斷 48 小時窗口的依據）');

            // 自動清除的主查詢：還沒處理的、落在窗口內的
            $table->index(['result', 'created_at']);
            // 介面要顯示某位同仁目前有幾則可清／幾則已過期
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('staff_dm_message')) {
            return;
        }

        Schema::dropIfExists('staff_dm_message');
    }
}

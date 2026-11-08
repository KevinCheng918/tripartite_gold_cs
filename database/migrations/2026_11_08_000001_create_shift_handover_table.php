<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 建立交接紀錄表（shift_handover）
 *
 * 每個交接窗口一列：上一班要在交接話題打交接事項，下一班要接手。
 * 沒交接就在話題 tag 兩邊，**催到完成為止**（需求方 2026-11-08：
 * 「就是催到他完成交接事項」，因為「他沒有就會打沒有了」）。
 *
 * ⚠ **為什麼需要這張表**：支援群組的訊息**不會存進 `telegram_message`**
 * （webhook 在 `isFromSupportChat()` 就攔掉，只在記憶體處理），
 * 所以「這一班交接了沒」沒辦法靠查訊息表反推，只能自己記。
 *
 * 詳見 .claude/knowledge/features/shift-handover-topic.md
 */
class CreateShiftHandoverTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('shift_handover')) {
            return;
        }

        Schema::create('shift_handover', function (Blueprint $table) {
            $table->id();

            /*
             * 交接日 ＝ **上一班回訊開始的那一天**，不是窗口那一天。
             *
             * ⚠ 兩者在大夜班那一窗會不一樣：大夜班回訊 23:00–09:00，
             * 它跟早班的交接窗口是**隔天** 08:00–09:00。排班紀錄
             * （`shift_assignments.date`）記的是 23:00 那天，所以要查
             * 「大夜班有誰」必須用這個 `date`，不能用 `window_start` 那天。
             */
            $table->date('date')->comment('上一班回訊開始日（查排班用，大夜班那一窗會早於窗口日）');

            $table->foreignId('from_shift_id')->constrained('shifts')->cascadeOnDelete()->comment('要打交接事項的那一班');
            $table->foreignId('to_shift_id')->constrained('shifts')->cascadeOnDelete()->comment('要接手的那一班');

            /*
             * 回訊時間重疊的那一段 —— 那就是交接窗口（需求方刻意設計的一小時）。
             *
             * ⚠ **存下來而不是每次重算**：班別的回訊時間在後台可以改，
             * 改了之後昨天那筆應該留著當時的窗口，不該跟著變
             * （同 `leave_request.minutes` 存當下換算結果的理由）。
             */
            $table->dateTime('window_start')->comment('重疊開始＝下一班回訊開始');
            $table->dateTime('window_end')->comment('重疊結束＝上一班回訊結束');

            $table->dateTime('handed_at')->nullable()->comment('完成交接的時間');

            // nullOnDelete 的欄位必須 nullable；帳號刪掉後靠 handed_by_name 還看得出是誰
            $table->foreignId('handed_by_user_id')->nullable()->constrained('user')->nullOnDelete()->comment('誰交接的');
            $table->string('handed_by_name', 100)->nullable()->comment('交接者名稱快照');

            /*
             * 交接那則訊息的 Telegram message_id。
             *
             * 下一班沒人可交接時（大夜班沒人），要發一則給下一班並**引用
             * 上一份交接** —— `SupportGroupService::send()` 的 `$replyTo`
             * 吃的就是這個。寫連結給人點不如直接引用。
             */
            $table->unsignedBigInteger('handed_message_id')->nullable()->comment('交接那則的 Telegram message_id（供下一班引用）');

            $table->unsignedInteger('remind_count')->default(0)->comment('催了幾次');
            $table->dateTime('last_reminded_at')->nullable()->comment('上次催的時間（間隔從這裡算）');

            /*
             * 「上一班今天沒人」那則已經跟下一班講過了。
             *
             * ⚠ 只發一次，所以需要這個欄位 —— 用 `status` 判斷不夠，
             * 狀態一開始就是「上一班沒人」，不代表已經通知過。
             */
            $table->dateTime('notified_next_at')->nullable()->comment('已經跟下一班說「上一班沒人」的時間');

            $table->tinyInteger('status')->default(1)->comment('1=待交接（催到完成）, 2=已交接, 3=上一班沒人（不催）');
            $table->timestamps();

            // 同一天同一組班別只會有一筆。排程每分鐘 firstOrCreate，重複跑不會長出第二筆
            $table->unique(['date', 'from_shift_id', 'to_shift_id'], 'shift_handover_date_shift_unique');

            // 排程每分鐘查「還沒交接的有哪些」
            $table->index(['status', 'window_start'], 'shift_handover_status_window_index');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('shift_handover');
    }
}

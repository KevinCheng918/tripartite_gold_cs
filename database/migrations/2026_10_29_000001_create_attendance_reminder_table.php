<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 打卡提醒的發送狀態
 *
 * 需求方 2026-10-29：「上班前 15 分鐘提醒、下班 30 分鐘後提醒，
 * 未打下班卡則每 30 分鐘持續提醒，平假日皆適用。」
 *
 * ⚠ **為什麼要存**：排程每分鐘跑一次，必須知道「這個人今天這一種提醒
 * 發過幾次、上一次什麼時候發的」—— 不存的話同一分鐘的誤差就會重複發，
 * 30 分鐘的間隔也算不準。
 *
 * ⚠ **不能把狀態放在 `attendance_records` 上**：上班前 15 分鐘那一則發出去時，
 * **那天的出勤紀錄還不存在**（紀錄是打卡當下才建的，或凌晨 01:00 的曠工
 * 排程補的）。
 *
 * ⚠ **不用 cache**：`CACHE_DRIVER` 預設是 `file`，重開就沒了 ——
 * 而「重開之後把今天發過的全部再發一次」正好是這種提醒最不能出的錯。
 */
class CreateAttendanceReminderTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('attendance_reminder')) {
            return;
        }

        Schema::create('attendance_reminder', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('user')->cascadeOnDelete()
                ->comment('提醒誰');

            /*
             * ⚠ 這是**出勤日期**（跨日班是「開始上班那天」），不是發送日期。
             * 22:00–06:00 的班在隔天 06:30 發的下班提醒，date 仍然是前一天 ——
             * 跟 `attendance_records.date` 的定義一致，兩邊才對得起來。
             */
            $table->date('date')->comment('出勤日期；跨日班是開始上班那天');

            $table->string('type', 10)->comment('clock_in=上班前, clock_out=下班後');

            $table->unsignedSmallInteger('sent_count')->default(0)
                ->comment('發過幾次；下班提醒會累加');

            $table->dateTime('last_sent_at')->nullable()
                ->comment('最後一次發出去的時間，算 30 分鐘間隔用');

            $table->timestamps();

            /*
             * ⚠ 唯一索引同時是**防重複的最後一道**：排程理論上不會重疊
             * （`withoutOverlapping`），但真的撞到時靠它擋住第二筆。
             */
            $table->unique(['user_id', 'date', 'type'], 'attendance_reminder_user_date_type_unq');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('attendance_reminder');
    }
}

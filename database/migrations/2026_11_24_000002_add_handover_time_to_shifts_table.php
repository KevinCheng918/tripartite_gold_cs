<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 班別可以自訂自己的交接時間
 *
 * 需求方 2026-11-24：「排班管理的班別設定這邊，我想要可以自定義每一班的
 * 交接時間。」
 *
 * ⚠ 在這之前交接時間是**推導**出來的：窗口起點固定等於「下一班的回訊
 * 開始時間」，提醒則在它之前 `SHIFT_HANDOVER_TOPIC.LEAD_MINUTES`（全域，
 * 預設 5 分鐘）開始。整個系統只有一個旋鈕，沒辦法讓某一班早一點交接。
 *
 * ⚠⚠ **留空＝維持原本的推導**，不是「不交接」。
 * 預設 null 是刻意的：這是新增的選項，不該在沒有人設定的情況下
 * 改變既有行為（同 2026-11-23 的 `session_timeout`）。
 *
 * 詳見 .claude/knowledge/features/shift-handover-topic.md
 */
class AddHandoverTimeToShiftsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (!Schema::hasTable('shifts')) {
            return;
        }

        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'handover_time')) {
                return;
            }

            /*
             * 這一班要在幾點把事情交接出去。
             *
             * ⚠ **是「交出去的那一班」的欄位**，不是接手那一班的 ——
             * 「早班幾點交接」問的是早班，答案才對得上使用者的直覺。
             *
             * ⚠ 存 `varchar(5)`（`HH:mm`）而不是 `time`，**跟
             * `reply_start_time`／`reply_end_time` 一致** —— 那兩欄就是
             * varchar，混用型別會讓比較與寫入各有各的坑。
             */
            $table->string('handover_time', 5)
                ->nullable()
                ->after('reply_end_time')
                ->comment('這一班的交接時間 HH:mm；留空＝沿用下一班回訊開始');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasTable('shifts')) {
            return;
        }

        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'handover_time')) {
                $table->dropColumn('handover_time');
            }
        });
    }
}

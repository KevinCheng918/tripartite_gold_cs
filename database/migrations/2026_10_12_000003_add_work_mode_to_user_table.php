<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 帳號加上「出勤模式」：輪班（吃排班）或固定時間
 *
 * 工程不在排班名單裡，所以遲到／早退／加班／曠工四個判定對他們全部失效 ——
 * 那四處都是從 `shift_assignments` 取時間的。固定班的人改成從帳號上拿。
 *
 * ⚠ 放 `user` 表而不是開新表：固定班就是一組起訖加幾個星期，
 * 一對一的資料開一張表只是多一次 join。
 *
 * ⚠ `work_days` 不能省。曠工是「該上班卻沒打卡」—— 沒有這一欄就不知道
 * 週六日算不算，固定班的人每個週末都會被標一天曠工。
 * 輪班的人不需要它：有排班的那天才要上班，這件事 assignment 已經回答了。
 */
class AddWorkModeToUserTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('user', 'work_mode')) {
            return;
        }

        Schema::table('user', function (Blueprint $table) {
            /*
             * 預設是輪班 —— 上線當天所有既有帳號維持現狀，不必補任何資料。
             * 對應 constants.USER.WORK_MODE。
             */
            $table->tinyInteger('work_mode')->default(1)->after('level')
                ->comment('出勤模式：1=輪班（吃排班） 2=固定時間');

            $table->time('work_start_time')->nullable()->after('work_mode')
                ->comment('固定班的上班時間');

            /*
             * ⚠ `work_end_time <= work_start_time` 就是跨日班（22:00–06:00）。
             * 不另外開一個 is_overnight 欄位 —— 那會變成兩個可以互相矛盾的來源，
             * 而跨日與否本來就能從這兩個值算出來。
             */
            $table->time('work_end_time')->nullable()->after('work_start_time')
                ->comment('固定班的下班時間。<= 上班時間代表跨日');

            /*
             * '1,2,3,4,5' = 週一到週五（ISO-8601：1=週一 … 7=週日）。
             *
             * ⚠ 跨日班指的是**開始上班那天**：勾了週五的 22:00–06:00，
             * 是週五晚上上班、週六清晨下班，不是週六晚上。
             */
            $table->string('work_days', 20)->nullable()->after('work_end_time')
                ->comment('固定班的上班日，逗號分隔的 1-7');

            /*
             * 固定班要不要出現在排班紀錄（需求方 2026-10-12 指定用獨立開關，
             * 不綁 level）—— 客服要看得到、工程不要，但哪天有「固定班卻不想
             * 出現在排班表」的客服也接得住。
             *
             * ⚠ 只對固定班有意義：輪班的人有實體 assignment，本來就一定顯示。
             */
            $table->boolean('show_in_shift')->default(false)->after('work_days')
                ->comment('固定班是否顯示在排班紀錄');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('user', 'work_mode')) {
            return;
        }

        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn(['work_mode', 'work_start_time', 'work_end_time', 'work_days', 'show_in_shift']);
        });
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * shifts 表新增 work_start 欄位
 *
 * 每個班別有自己的上班時間（可能早於班別開始時間），
 * 遲到/早退依此欄位判斷。
 */
class AddWorkStartToShiftsTable extends Migration
{
    /**
     * @return void
     */
    public function up()
    {
        if (Schema::hasColumn('shifts', 'work_start')) {
            return;
        }

        Schema::table('shifts', function (Blueprint $table) {
            $table->time('work_start')->nullable()->after('start_time')->comment('上班時間（打卡基準，可能早於班別開始時間）');
        });
    }

    /**
     * @return void
     */
    public function down()
    {
        if (!Schema::hasColumn('shifts', 'work_start')) {
            return;
        }

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('work_start');
        });
    }
}

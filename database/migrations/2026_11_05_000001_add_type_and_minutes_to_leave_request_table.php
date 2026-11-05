<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 請假加上「假別」與「換算後的分鐘數」
 *
 * 需求方 2026-11-05：請假要能選特休／病假／事假，用小時計算，
 * 特休請完就不能再請。
 *
 * ⚠ **為什麼要存 `minutes` 而不是每次算**：特休額度是「日」而請假有整天與
 * 時段兩種，原本整天假只記天數、時段假只記小時，**兩個數字從來沒有換算成
 * 同一個單位**，所以「特休剩幾小時」算不出來。
 *
 * ⚠ 而且「一日幾小時」是**跟著人走的**（輪班 12、固定班 8，需求方裁示）——
 * 那個人日後換了出勤模式，舊假單不該跟著變。存當下換算的結果才對得起帳。
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('leave_request')) {
            return;
        }

        Schema::table('leave_request', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_request', 'type')) {
                /*
                 * ⚠ 既有假單一律填 `unclassified`（需求方裁示）。
                 *
                 * 填「特休」的話，歷史假單會直接去扣現在的特休額度；
                 * 填「事假」則會被誤讀成「他當時真的請事假」。
                 * 另開一個值，一看就知道是改版前的資料。
                 */
                $table->string('type', 20)
                    ->default('unclassified')
                    ->after('end_date')
                    ->comment('假別：annual=特休, sick=病假, personal=事假, unclassified=改版前的舊資料');
            }

            if (!Schema::hasColumn('leave_request', 'minutes')) {
                /*
                 * ⚠ 既有假單一律 0：它們是 `unclassified`，不扣任何額度，
                 * 算不算得出分鐘數都不影響結果。回頭補算反而要猜當時的班別。
                 */
                $table->unsignedInteger('minutes')
                    ->default(0)
                    ->after('end_time')
                    ->comment('這張假單總共幾分鐘（整天假已依該員的日時數換算）');
            }
        });

        /*
         * ⚠ 查「某人某段期間的特休用了多少」會同時帶 user_id、type、status
         * 與日期範圍 —— 沒有索引的話每次算剩餘額度都是全表掃。
         *
         * ⚠ **用原生 `SHOW INDEX` 判斷存不存在，不要用
         * `getDoctrineSchemaManager()`** —— 這個專案**沒有裝 doctrine/dbal**，
         * 那一支會直接丟例外，而 migration 丟例外就是整個停在半路。
         */
        $exists = collect(DB::select('SHOW INDEX FROM `leave_request`'))
            ->pluck('Key_name')
            ->contains('leave_request_quota_idx');

        if (!$exists) {
            Schema::table('leave_request', function (Blueprint $table) {
                $table->index(['user_id', 'type', 'status'], 'leave_request_quota_idx');
            });
        }
    }

    public function down()
    {
        if (!Schema::hasTable('leave_request')) {
            return;
        }

        Schema::table('leave_request', function (Blueprint $table) {
            $table->dropIndex('leave_request_quota_idx');
            $table->dropColumn(['type', 'minutes']);
        });
    }
};
